<?php

namespace App;

use Movim\Model;
use Movim\Picture;
use Movim\Route;

use Illuminate\Database\QueryException;

class Message extends Model
{
    protected $primaryKey = ['user_id', 'jidfrom', 'id'];
    public $incrementing = false;
    public $mucpm; // Only used in Message Payloads to detect composer/paused PM messages

    protected $guarded = [];

    protected $with = ['reactions'];

    protected $attributes = [
        'type'    => 'chat'
    ];

    protected $casts = [
        'quoted'   => 'boolean',
        'markable' => 'boolean'
    ];

    public function user()
    {
        return $this->belongsTo('App\User');
    }

    public function reactions()
    {
        return $this->hasMany('App\Reaction', 'message_mid', 'mid');
    }

    public function setFileAttribute(array $file)
    {
        $this->attributes['file'] = serialize($file);
    }

    public function getFileAttribute()
    {
        if (isset($this->attributes['file'])) {
            $file = unserialize($this->attributes['file']);

            if (\array_key_exists('size', $file)) {
                $file['cleansize'] = sizeToCleanSize($file['size']);
            }

            return $file;
        }

        return null;
    }

    public static function findByStanza($stanza)
    {
        /**
         * If this stanza replaces another one, we load the original message
         */
        if ($stanza->replace) {
            return self::firstOrNew([
                'user_id' => \App\User::me()->id,
                'replaceid' => (string)$stanza->replace->attributes()->id,
                'jidfrom' => current(explode('/', (string)$stanza->attributes()->from))
            ]);
        }

        /**
         * If not we just create or load a message
         */
        $id = ($stanza->{'stanza-id'} && $stanza->{'stanza-id'}->attributes()->id)
            ? (string)$stanza->{'stanza-id'}->attributes()->id
            : 'm_' . generateUUID();

        return self::firstOrNew([
            'user_id' => \App\User::me()->id,
            'id' => $id,
            'jidfrom' => current(explode('/', (string)$stanza->attributes()->from))
        ]);
    }

    public function set($stanza, $parent = false)
    {
        $this->id = ($stanza->{'stanza-id'} && $stanza->{'stanza-id'}->attributes()->id)
            ? (string)$stanza->{'stanza-id'}->attributes()->id
            : 'm_' . generateUUID();

        if ($stanza->attributes()->id) {
            $this->replaceid = $stanza->attributes()->id;
        }

        $jid = explode('/', (string)$stanza->attributes()->from);
        $to = current(explode('/', (string)$stanza->attributes()->to));

        $this->user_id    = \App\User::me()->id;

        if (!$this->jidto) {
            $this->jidto      = $to;
        }

        if (!$this->jidfrom) {
            $this->jidfrom    = $jid[0];
        }

        // If the message is from me
        if ($this->jidfrom == $this->user_id) {
            $this->seen = true;
        }

        if (isset($jid[1])) {
            $this->resource = $jid[1];
        }

        if ($stanza->delay) {
            $this->published = gmdate('Y-m-d H:i:s', strtotime($stanza->delay->attributes()->stamp));
        } elseif ($parent && $parent->delay) {
            $this->published = gmdate('Y-m-d H:i:s', strtotime($parent->delay->attributes()->stamp));
        } elseif (!isset($stanza->replace) || $this->published === null) {
            $this->published = gmdate('Y-m-d H:i:s');
        }

        $this->type = 'chat';
        if ($stanza->attributes()->type) {
            $this->type = (string)$stanza->attributes()->type;
        }

        if (isset($jid[1])
        && $this->type !== 'groupchat'
        && $stanza->x
        && (string)$stanza->x->attributes()->xmlns == 'http://jabber.org/protocol/muc#user') {
            $this->mucpm = true;
            $this->jidfrom = $jid[0].'/'.$jid[1];
        }

        if ($stanza->body || $stanza->subject) {
            if (isset($stanza->attributes()->id)) {
                $this->id = (string)$stanza->attributes()->id;
            }

            if ($stanza->body) {
                $this->body = (string)$stanza->body;
            }

            # HipChat MUC specific cards
            if (in_array(
                explodeJid($this->jidfrom)['server'],
                ['conf.hipchat.com', 'conf.btf.hipchat.com']
            )
            && $this->type == 'groupchat'
            && $stanza->x
            && $stanza->x->attributes()->xmlns == 'http://hipchat.com/protocol/muc#room'
            && $stanza->x->card) {
                $this->body = trim(html_entity_decode($this->body));
            }

            $this->markable = (bool)($stanza->markable);

            if ($stanza->subject) {
                $this->type = 'subject';
                $this->subject = (string)$stanza->subject;
            }

            if ($stanza->thread) {
                $this->thread = (string)$stanza->thread;
            }

            if ($this->type == 'groupchat') {
                $presence = $this->user->session->presences()
                                 ->where('jid', $this->jidfrom)
                                 ->where('mucjid', $this->user->id)
                                 ->first();

                if ($presence
                && strpos($this->body, $presence->resource) !== false
                && $this->resource != $presence->resource) {
                    $this->quoted = true;
                }
            }

            if ($stanza->html) {
                $results = [];

                $xml = \simplexml_load_string((string)$stanza->html);
                if (!$xml) {
                    $xml = \simplexml_load_string((string)$stanza->html->body);
                    if ($xml) {
                        $results = $xml->xpath('//img/@src');
                    }
                } else {
                    $xml->registerXPathNamespace('xhtml', 'http://www.w3.org/1999/xhtml');
                    $results = $xml->xpath('//xhtml:img/@src');
                }

                if (!empty($results)) {
                    if (substr((string)$results[0], 0, 10) == 'data:image') {
                        $str = explode('base64,', $results[0]);
                        if (isset($str[1])) {
                            $p = new Picture;
                            $p->fromBase(urldecode($str[1]));
                            $key = sha1(urldecode($str[1]));
                            $p->set($key, 'png');

                            $this->sticker = $key;
                        }
                    } else {
                        $this->sticker = getCid((string)$results[0]);
                    }
                }
            }

            if ($stanza->reference
            && (string)$stanza->reference->attributes()->xmlns == 'urn:xmpp:reference:0') {
                $filetmp = [];

                if ($stanza->reference->{'media-sharing'}
                && (string)$stanza->reference->{'media-sharing'}->attributes()->xmlns == 'urn:xmpp:sims:1') {
                    $file = $stanza->reference->{'media-sharing'}->file;
                    if (isset($file)) {
                        if (preg_match('/\w+\/[-+.\w]+/', $file->{'media-type'}) == 1) {
                            $filetmp['type'] = (string)$file->{'media-type'};
                        }
                        $filetmp['size'] = (int)$file->size;
                        $filetmp['name'] = (string)$file->name;
                    }

                    if ($stanza->reference->{'media-sharing'}->sources) {
                        $source = $stanza->reference->{'media-sharing'}->sources->reference;

                        if (!filter_var((string)$source->attributes()->uri, FILTER_VALIDATE_URL) === false) {
                            $filetmp['uri'] = (string)$source->attributes()->uri;
                        }
                    }

                    if (array_key_exists('uri', $filetmp)
                    && array_key_exists('type', $filetmp)
                    && array_key_exists('size', $filetmp)
                    && array_key_exists('name', $filetmp)) {
                        $this->file = $filetmp;
                    }
                } elseif (\in_array($stanza->reference->attributes()->type, ['mention', 'data'])
                    && $stanza->reference->attributes()->uri) {

                    $uri = parse_url($stanza->reference->attributes()->uri);

                    if ($uri['scheme'] === 'xmpp') {
                        $begin = '<a href="' . Route::urlize('share', $stanza->reference->attributes()->uri) . '">';

                        if ($stanza->reference->attributes()->begin && $stanza->reference->attributes()->end) {
                            $this->html = substr_replace(
                                $this->body,
                                $begin,
                                (int)$stanza->reference->attributes()->begin,
                                0
                            );
                            $this->html = substr_replace(
                                $this->html,
                                '</a>',
                                (int)$stanza->reference->attributes()->end + strlen($begin),
                                0
                            );
                        } else {
                            $this->html = $begin . $this->body . '</a>';
                        }

                        $this->file = [
                            'type' => 'xmpp',
                            'uri' => (string)$stanza->reference->attributes()->uri,
                        ];
                    }
                }
            }

            if ($stanza->replace
            && $this->user->messages()
                ->where('jidfrom', $this->jidfrom)
                ->where('replaceid', $this->replaceid)
                ->count() == 0
            ) {
                $message = $this->user->messages()
                                ->where('jidfrom', $this->jidfrom)
                                ->where('replaceid', (string)$stanza->replace->attributes()->id)
                                ->first();
                $this->oldid = $message->id;

                /**
                 * We prepare the existing message to be edited in the DB
                 */
                Message::where('replaceid', (string)$stanza->replace->attributes()->id)
                ->where('user_id', $this->user_id)
                ->where('jidfrom', $this->jidfrom)
                ->update(['id' => $this->id]);
            }

            if (isset($stanza->x->invite)) {
                $this->type = 'invitation';
                $this->subject = $this->jidfrom;
                $this->jidfrom = current(explode('/', (string)$stanza->x->invite->attributes()->from));
            }

            return $this->checkPicture();
        } elseif (isset($stanza->x)
            && $stanza->x->attributes()->xmlns == 'jabber:x:conference') {
            $this->type = 'invitation';
            $this->body = (string)$stanza->x->attributes()->reason;
            $this->subject = (string)$stanza->x->attributes()->jid;
        }

        # XEP-xxxx: Message Reactions
        elseif (isset($stanza->reactions)
            && $stanza->reactions->attributes()->xmlns == 'urn:xmpp:reactions:0') {

            $parentMessage = $this->user
                ->messages()
                ->where('replaceid', (string)$stanza->reactions->attributes()->to)
                ->where(function ($query)  {
                    $query->where('jidfrom', $this->jidfrom)
                        ->orWhere('jidto', $this->jidfrom);
                })
                ->first();

            if ($parentMessage) {
                $resource = ($this->type == 'groupchat')
                    ? $this->resource
                    : $this->jidfrom;

                $parentMessage
                    ->reactions()
                    ->where('jidfrom', $resource)
                    ->delete();

                $emojis = [];
                $now = \Carbon\Carbon::now();
                $emoji = \Movim\Emoji::getInstance();

                foreach ($stanza->reactions->reaction as $children) {
                    $emoji->replace((string)$children);
                    if ($emoji->isSingleEmoji()) {
                        $reaction = new Reaction;
                        $reaction->message_mid = $parentMessage->mid;
                        $reaction->emoji = (string)$children;
                        $reaction->jidfrom = $resource;
                        $reaction->created_at = $now;
                        $reaction->updated_at = $now;

                        \array_push($emojis, $reaction->toArray());
                    }
                }

                try {
                    Reaction::insert($emojis);
                } catch (QueryException $exception) {
                    // Duplicate ?
                }

                return $parentMessage;
            }
        }

        return $this;
    }

    public function isTrusted()
    {
        $rd = new \Modl\RosterLinkDAO;
        $from = explode('@', cleanJid((string)$this->jidfrom));
        $from = explode('.', end($from));

        $session = explode('@',(string)$this->session);

        return ($this->session == $this->jidfrom
            || end($session) == $from[count($from)-2].'.'.$from[count($from)-1]
            || $rd->get($this->jidfrom) !== null);
    }

    public function isEmpty()
    {
        return (empty($this->body)
            && empty($this->picture)
            && empty($this->file)
            && empty($this->sticker)
        );
    }

    public function isSubject()
    {
        return !empty($this->subject);
    }

    public function isOTR()
    {
        return preg_match('#^\?OTR#', $this->body);
    }

    public function addUrls()
    {
        if (is_string($this->body)) {
            $this->body = addUrls($this->body);
        }
    }
}
