<?php
/*
 * StatusNet - the distributed open-source microblogging tool
 * Copyright (C) 2009-2010, StatusNet, Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * @package OStatusPlugin
 * @maintainer Brion Vibber <brion@status.net>
 */

class Ostatus_profile extends Memcached_DataObject
{
    public $__table = 'ostatus_profile';

    public $uri;

    public $profile_id;
    public $group_id;

    public $feeduri;
    public $salmonuri;
    public $avatar; // remote URL of the last avatar we saved

    public $created;
    public $modified;

    public /*static*/ function staticGet($k, $v=null)
    {
        return parent::staticGet(__CLASS__, $k, $v);
    }

    /**
     * return table definition for DB_DataObject
     *
     * DB_DataObject needs to know something about the table to manipulate
     * instances. This method provides all the DB_DataObject needs to know.
     *
     * @return array array of column definitions
     */

    function table()
    {
        return array('uri' => DB_DATAOBJECT_STR + DB_DATAOBJECT_NOTNULL,
                     'profile_id' => DB_DATAOBJECT_INT,
                     'group_id' => DB_DATAOBJECT_INT,
                     'feeduri' => DB_DATAOBJECT_STR,
                     'salmonuri' =>  DB_DATAOBJECT_STR,
                     'avatar' =>  DB_DATAOBJECT_STR,
                     'created' => DB_DATAOBJECT_STR + DB_DATAOBJECT_DATE + DB_DATAOBJECT_TIME + DB_DATAOBJECT_NOTNULL,
                     'modified' => DB_DATAOBJECT_STR + DB_DATAOBJECT_DATE + DB_DATAOBJECT_TIME + DB_DATAOBJECT_NOTNULL);
    }

    static function schemaDef()
    {
        return array(new ColumnDef('uri', 'varchar',
                                   255, false, 'PRI'),
                     new ColumnDef('profile_id', 'integer',
                                   null, true, 'UNI'),
                     new ColumnDef('group_id', 'integer',
                                   null, true, 'UNI'),
                     new ColumnDef('feeduri', 'varchar',
                                   255, true, 'UNI'),
                     new ColumnDef('salmonuri', 'text',
                                   null, true),
                     new ColumnDef('avatar', 'text',
                                   null, true),
                     new ColumnDef('created', 'datetime',
                                   null, false),
                     new ColumnDef('modified', 'datetime',
                                   null, false));
    }

    /**
     * return key definitions for DB_DataObject
     *
     * DB_DataObject needs to know about keys that the table has; this function
     * defines them.
     *
     * @return array key definitions
     */

    function keys()
    {
        return array_keys($this->keyTypes());
    }

    /**
     * return key definitions for Memcached_DataObject
     *
     * Our caching system uses the same key definitions, but uses a different
     * method to get them.
     *
     * @return array key definitions
     */

    function keyTypes()
    {
        return array('uri' => 'K', 'profile_id' => 'U', 'group_id' => 'U', 'feeduri' => 'U');
    }

    function sequenceKey()
    {
        return array(false, false, false);
    }

    /**
     * Fetch the StatusNet-side profile for this feed
     * @return Profile
     */
    public function localProfile()
    {
        if ($this->profile_id) {
            return Profile::staticGet('id', $this->profile_id);
        }
        return null;
    }

    /**
     * Fetch the StatusNet-side profile for this feed
     * @return Profile
     */
    public function localGroup()
    {
        if ($this->group_id) {
            return User_group::staticGet('id', $this->group_id);
        }
        return null;
    }

    /**
     * Returns an ActivityObject describing this remote user or group profile.
     * Can then be used to generate Atom chunks.
     *
     * @return ActivityObject
     */
    function asActivityObject()
    {
        if ($this->isGroup()) {
            return ActivityObject::fromGroup($this->localGroup());
        } else {
            return ActivityObject::fromProfile($this->localProfile());
        }
    }

    /**
     * Returns an XML string fragment with profile information as an
     * Activity Streams noun object with the given element type.
     *
     * Assumes that 'activity' namespace has been previously defined.
     *
     * @fixme replace with wrappers on asActivityObject when it's got everything.
     *
     * @param string $element one of 'actor', 'subject', 'object', 'target'
     * @return string
     */
    function asActivityNoun($element)
    {
        if ($this->isGroup()) {
            $noun = ActivityObject::fromGroup($this->localGroup());
            return $noun->asString('activity:' . $element);
        } else {
            $noun = ActivityObject::fromProfile($this->localProfile());
            return $noun->asString('activity:' . $element);
        }
    }

    /**
     * @return boolean true if this is a remote group
     */
    function isGroup()
    {
        if ($this->profile_id && !$this->group_id) {
            return false;
        } else if ($this->group_id && !$this->profile_id) {
            return true;
        } else if ($this->group_id && $this->profile_id) {
            throw new ServerException("Invalid ostatus_profile state: both group and profile IDs set for $this->uri");
        } else {
            throw new ServerException("Invalid ostatus_profile state: both group and profile IDs empty for $this->uri");
        }
    }

    /**
     * Subscribe a local user to this remote user.
     * PuSH subscription will be started if necessary, and we'll
     * send a Salmon notification to the remote server if available
     * notifying them of the sub.
     *
     * @param User $user
     * @return boolean success
     * @throws FeedException
     */
    public function subscribeLocalToRemote(User $user)
    {
        if ($this->isGroup()) {
            throw new ServerException("Can't subscribe to a remote group");
        }

        if ($this->subscribe()) {
            if ($user->subscribeTo($this->localProfile())) {
                $this->notify($user->getProfile(), ActivityVerb::FOLLOW, $this);
                return true;
            }
        }
        return false;
    }

    /**
     * Mark this remote profile as subscribing to the given local user,
     * and send appropriate notifications to the user.
     *
     * This will generally be in response to a subscription notification
     * from a foreign site to our local Salmon response channel.
     *
     * @param User $user
     * @return boolean success
     */
    public function subscribeRemoteToLocal(User $user)
    {
        if ($this->isGroup()) {
            throw new ServerException("Remote groups can't subscribe to local users");
        }

        Subscription::start($this->localProfile(), $user->getProfile());

        return true;
    }

    /**
     * Send a subscription request to the hub for this feed.
     * The hub will later send us a confirmation POST to /main/push/callback.
     *
     * @return bool true on success, false on failure
     * @throws ServerException if feed state is not valid
     */
    public function subscribe()
    {
        $feedsub = FeedSub::ensureFeed($this->feeduri);
        if ($feedsub->sub_state == 'active' || $feedsub->sub_state == 'subscribe') {
            return true;
        } else if ($feedsub->sub_state == '' || $feedsub->sub_state == 'inactive') {
            return $feedsub->subscribe();
        } else if ('unsubscribe') {
            throw new FeedSubException("Unsub is pending, can't subscribe...");
        }
    }

    /**
     * Send a PuSH unsubscription request to the hub for this feed.
     * The hub will later send us a confirmation POST to /main/push/callback.
     *
     * @return bool true on success, false on failure
     * @throws ServerException if feed state is not valid
     */
    public function unsubscribe() {
        $feedsub = FeedSub::staticGet('uri', $this->feeduri);
        if (!$feedsub) {
            return true;
        }
        if ($feedsub->sub_state == 'active') {
            return $feedsub->unsubscribe();
        } else if ($feedsub->sub_state == '' || $feedsub->sub_state == 'inactive' || $feedsub->sub_state == 'unsubscribe') {
            return true;
        } else if ($feedsub->sub_state == 'subscribe') {
            throw new FeedSubException("Feed is awaiting subscription, can't unsub...");
        }
    }

    /**
     * Check if this remote profile has any active local subscriptions, and
     * if not drop the PuSH subscription feed.
     *
     * @return boolean
     */
    public function garbageCollect()
    {
        if ($this->isGroup()) {
            $members = $this->localGroup()->getMembers(0, 1);
            $count = $members->N;
        } else {
            $count = $this->localProfile()->subscriberCount();
        }
        if ($count == 0) {
            common_log(LOG_INFO, "Unsubscribing from now-unused remote feed $this->feeduri");
            $this->unsubscribe();
            return true;
        } else {
            return false;
        }
    }

    /**
     * Send an Activity Streams notification to the remote Salmon endpoint,
     * if so configured.
     *
     * @param Profile $actor  Actor who did the activity
     * @param string  $verb   Activity::SUBSCRIBE or Activity::JOIN
     * @param Object  $object object of the action; must define asActivityNoun($tag)
     */
    public function notify($actor, $verb, $object=null)
    {
        if (!($actor instanceof Profile)) {
            $type = gettype($actor);
            if ($type == 'object') {
                $type = get_class($actor);
            }
            throw new ServerException("Invalid actor passed to " . __METHOD__ . ": " . $type);
        }
        if ($object == null) {
            $object = $this;
        }
        if ($this->salmonuri) {

            $text = 'update';
            $id = TagURI::mint('%s:%s:%s',
                               $verb,
                               $actor->getURI(),
                               common_date_iso8601(time()));

            // @fixme consolidate all these NS settings somewhere
            $attributes = array('xmlns' => Activity::ATOM,
                                'xmlns:activity' => 'http://activitystrea.ms/spec/1.0/',
                                'xmlns:thr' => 'http://purl.org/syndication/thread/1.0',
                                'xmlns:georss' => 'http://www.georss.org/georss',
                                'xmlns:ostatus' => 'http://ostatus.org/schema/1.0',
                                'xmlns:poco' => 'http://portablecontacts.net/spec/1.0',
                                'xmlns:media' => 'http://purl.org/syndication/atommedia');

            $entry = new XMLStringer();
            $entry->elementStart('entry', $attributes);
            $entry->element('id', null, $id);
            $entry->element('title', null, $text);
            $entry->element('summary', null, $text);
            $entry->element('published', null, common_date_w3dtf(common_sql_now()));

            $entry->element('activity:verb', null, $verb);
            $entry->raw($actor->asAtomAuthor());
            $entry->raw($actor->asActivityActor());
            $entry->raw($object->asActivityNoun('object'));
            $entry->elementEnd('entry');

            $xml = $entry->getString();
            common_log(LOG_INFO, "Posting to Salmon endpoint $this->salmonuri: $xml");

            $salmon = new Salmon(); // ?
            return $salmon->post($this->salmonuri, $xml, $actor);
        }
        return false;
    }

    /**
     * Send a Salmon notification ping immediately, and confirm that we got
     * an acceptable response from the remote site.
     *
     * @param mixed $entry XML string, Notice, or Activity
     * @return boolean success
     */
    public function notifyActivity($entry, $actor)
    {
        if ($this->salmonuri) {
            $salmon = new Salmon();
            return $salmon->post($this->salmonuri, $this->notifyPrepXml($entry), $actor);
        }

        return false;
    }

    /**
     * Queue a Salmon notification for later. If queues are disabled we'll
     * send immediately but won't get the return value.
     *
     * @param mixed $entry XML string, Notice, or Activity
     * @return boolean success
     */
    public function notifyDeferred($entry, $actor)
    {
        if ($this->salmonuri) {
            $data = array('salmonuri' => $this->salmonuri,
                          'entry' => $this->notifyPrepXml($entry),
                          'actor' => $actor->id);

            $qm = QueueManager::get();
            return $qm->enqueue($data, 'salmon');
        }

        return false;
    }

    protected function notifyPrepXml($entry)
    {
        $preamble = '<?xml version="1.0" encoding="UTF-8" ?' . '>';
        if (is_string($entry)) {
            return $entry;
        } else if ($entry instanceof Activity) {
            return $preamble . $entry->asString(true);
        } else if ($entry instanceof Notice) {
            return $preamble . $entry->asAtomEntry(true, true);
        } else {
            throw new ServerException("Invalid type passed to Ostatus_profile::notify; must be XML string or Activity entry");
        }
    }

    function getBestName()
    {
        if ($this->isGroup()) {
            return $this->localGroup()->getBestName();
        } else {
            return $this->localProfile()->getBestName();
        }
    }

    /**
     * Read and post notices for updates from the feed.
     * Currently assumes that all items in the feed are new,
     * coming from a PuSH hub.
     *
     * @param DOMDocument $doc
     * @param string $source identifier ("push")
     */
    public function processFeed(DOMDocument $doc, $source)
    {
        $feed = $doc->documentElement;

        if ($feed->localName != 'feed' || $feed->namespaceURI != Activity::ATOM) {
            common_log(LOG_ERR, __METHOD__ . ": not an Atom feed, ignoring");
            return;
        }

        $entries = $feed->getElementsByTagNameNS(Activity::ATOM, 'entry');
        if ($entries->length == 0) {
            common_log(LOG_ERR, __METHOD__ . ": no entries in feed update, ignoring");
            return;
        }

        for ($i = 0; $i < $entries->length; $i++) {
            $entry = $entries->item($i);
            $this->processEntry($entry, $feed, $source);
        }
    }

    /**
     * Process a posted entry from this feed source.
     *
     * @param DOMElement $entry
     * @param DOMElement $feed for context
     * @param string $source identifier ("push" or "salmon")
     */
    public function processEntry($entry, $feed, $source)
    {
        $activity = new Activity($entry, $feed);

        if ($activity->verb == ActivityVerb::POST) {
            $this->processPost($activity, $source);
        } else {
            common_log(LOG_INFO, "Ignoring activity with unrecognized verb $activity->verb");
        }
    }

    /**
     * Process an incoming post activity from this remote feed.
     * @param Activity $activity
     * @param string $method 'push' or 'salmon'
     * @return mixed saved Notice or false
     * @fixme break up this function, it's getting nasty long
     */
    public function processPost($activity, $method)
    {
        if ($this->isGroup()) {
            // A group feed will contain posts from multiple authors.
            // @fixme validate these profiles in some way!
            $oprofile = self::ensureActorProfile($activity);
            if ($oprofile->isGroup()) {
                // Groups can't post notices in StatusNet.
                common_log(LOG_WARNING, "OStatus: skipping post with group listed as author: $oprofile->uri in feed from $this->uri");
                return false;
            }
        } else {
            // Individual user feeds may contain only posts from themselves.
            // Authorship is validated against the profile URI on upper layers,
            // through PuSH setup or Salmon signature checks.
            $actorUri = self::getActorProfileURI($activity);
            if ($actorUri == $this->uri) {
                // Check if profile info has changed and update it
                $this->updateFromActivityObject($activity->actor);
            } else {
                common_log(LOG_WARNING, "OStatus: skipping post with bad author: got $actorUri expected $this->uri");
                return false;
            }
            $oprofile = $this;
        }

        // The id URI will be used as a unique identifier for for the notice,
        // protecting against duplicate saves. It isn't required to be a URL;
        // tag: URIs for instance are found in Google Buzz feeds.
        $sourceUri = $activity->object->id;
        $dupe = Notice::staticGet('uri', $sourceUri);
        if ($dupe) {
            common_log(LOG_INFO, "OStatus: ignoring duplicate post: $sourceUri");
            return false;
        }

        // We'll also want to save a web link to the original notice, if provided.
        $sourceUrl = null;
        if ($activity->object->link) {
            $sourceUrl = $activity->object->link;
        } else if ($activity->link) {
            $sourceUrl = $activity->link;
        } else if (preg_match('!^https?://!', $activity->object->id)) {
            $sourceUrl = $activity->object->id;
        }

        // Get (safe!) HTML and text versions of the content
        $rendered = $this->purify($activity->object->content);
        $content = html_entity_decode(strip_tags($rendered));

        $shortened = common_shorten_links($content);

        // If it's too long, try using the summary, and make the
        // HTML an attachment.

        $attachment = null;

        if (Notice::contentTooLong($shortened)) {
            $attachment = $this->saveHTMLFile($activity->object->title, $rendered);
            $summary = $activity->object->summary;
            if (empty($summary)) {
                $summary = $content;
            }
            $shortSummary = common_shorten_links($summary);
            if (Notice::contentTooLong($shortSummary)) {
                $url = common_shorten_url(common_local_url('attachment',
                                                           array('attachment' => $attachment->id)));
                $shortSummary = substr($shortSummary,
                                       0,
                                       Notice::maxContent() - (mb_strlen($url) + 2));
                $shortSummary .= '… ' . $url;
                $content = $shortSummary;
                $rendered = common_render_text($content);
            }
        }

        $options = array('is_local' => Notice::REMOTE_OMB,
                        'url' => $sourceUrl,
                        'uri' => $sourceUri,
                        'rendered' => $rendered,
                        'replies' => array(),
                        'groups' => array(),
                        'tags' => array(),
                        'urls' => array());

        // Check for optional attributes...

        if (!empty($activity->time)) {
            $options['created'] = common_sql_date($activity->time);
        }

        if ($activity->context) {
            // Any individual or group attn: targets?
            $replies = $activity->context->attention;
            $options['groups'] = $this->filterReplies($oprofile, $replies);
            $options['replies'] = $replies;

            // Maintain direct reply associations
            // @fixme what about conversation ID?
            if (!empty($activity->context->replyToID)) {
                $orig = Notice::staticGet('uri',
                                          $activity->context->replyToID);
                if (!empty($orig)) {
                    $options['reply_to'] = $orig->id;
                }
            }

            $location = $activity->context->location;
            if ($location) {
                $options['lat'] = $location->lat;
                $options['lon'] = $location->lon;
                if ($location->location_id) {
                    $options['location_ns'] = $location->location_ns;
                    $options['location_id'] = $location->location_id;
                }
            }
        }

        // Atom categories <-> hashtags
        foreach ($activity->categories as $cat) {
            if ($cat->term) {
                $term = common_canonical_tag($cat->term);
                if ($term) {
                    $options['tags'][] = $term;
                }
            }
        }

        // Atom enclosures -> attachment URLs
        foreach ($activity->enclosures as $href) {
            // @fixme save these locally or....?
            $options['urls'][] = $href;
        }

        try {
            $saved = Notice::saveNew($oprofile->profile_id,
                                     $content,
                                     'ostatus',
                                     $options);
            if ($saved) {
                Ostatus_source::saveNew($saved, $this, $method);
                if (!empty($attachment)) {
                    File_to_post::processNew($attachment->id, $saved->id);
                }
            }
        } catch (Exception $e) {
            common_log(LOG_ERR, "OStatus save of remote message $sourceUri failed: " . $e->getMessage());
            throw $e;
        }
        common_log(LOG_INFO, "OStatus saved remote message $sourceUri as notice id $saved->id");
        return $saved;
    }

    /**
     * Clean up HTML
     */
    protected function purify($html)
    {
        require_once INSTALLDIR.'/extlib/htmLawed/htmLawed.php';
        $config = array('safe' => 1,
                        'deny_attribute' => 'id,style,on*');
        return htmLawed($html, $config);
    }

    /**
     * Filters a list of recipient ID URIs to just those for local delivery.
     * @param Ostatus_profile local profile of sender
     * @param array in/out &$attention_uris set of URIs, will be pruned on output
     * @return array of group IDs
     */
    protected function filterReplies($sender, &$attention_uris)
    {
        common_log(LOG_DEBUG, "Original reply recipients: " . implode(', ', $attention_uris));
        $groups = array();
        $replies = array();
        foreach ($attention_uris as $recipient) {
            // Is the recipient a local user?
            $user = User::staticGet('uri', $recipient);
            if ($user) {
                // @fixme sender verification, spam etc?
                $replies[] = $recipient;
                continue;
            }

            // Is the recipient a remote group?
            $oprofile = Ostatus_profile::staticGet('uri', $recipient);
            if ($oprofile) {
                if ($oprofile->isGroup()) {
                    // Deliver to local members of this remote group.
                    // @fixme sender verification?
                    $groups[] = $oprofile->group_id;
                } else {
                    common_log(LOG_DEBUG, "Skipping reply to remote profile $recipient");
                }
                continue;
            }

            // Is the recipient a local group?
            // @fixme we need a uri on user_group
            // $group = User_group::staticGet('uri', $recipient);
            $template = common_local_url('groupbyid', array('id' => '31337'));
            $template = preg_quote($template, '/');
            $template = str_replace('31337', '(\d+)', $template);
            if (preg_match("/$template/", $recipient, $matches)) {
                $id = $matches[1];
                $group = User_group::staticGet('id', $id);
                if ($group) {
                    // Deliver to all members of this local group if allowed.
                    $profile = $sender->localProfile();
                    if ($profile->isMember($group)) {
                        $groups[] = $group->id;
                    } else {
                        common_log(LOG_DEBUG, "Skipping reply to local group $group->nickname as sender $profile->id is not a member");
                    }
                    continue;
                } else {
                    common_log(LOG_DEBUG, "Skipping reply to bogus group $recipient");
                }
            }

            common_log(LOG_DEBUG, "Skipping reply to unrecognized profile $recipient");

        }
        $attention_uris = $replies;
        common_log(LOG_DEBUG, "Local reply recipients: " . implode(', ', $replies));
        common_log(LOG_DEBUG, "Local group recipients: " . implode(', ', $groups));
        return $groups;
    }

    /**
     * @param string $profile_url
     * @return Ostatus_profile
     * @throws FeedSubException
     */
    public static function ensureProfile($profile_uri, $hints=array())
    {
        // Get the canonical feed URI and check it
        $discover = new FeedDiscovery();
        if (isset($hints['feedurl'])) {
            $feeduri = $hints['feedurl'];
            $feeduri = $discover->discoverFromFeedURL($feeduri);
        } else {
            $feeduri = $discover->discoverFromURL($profile_uri);
            $hints['feedurl'] = $feeduri;
        }

        $huburi = $discover->getAtomLink('hub');
        $hints['hub'] = $huburi;
        $salmonuri = $discover->getAtomLink(Salmon::NS_REPLIES);
        $hints['salmon'] = $salmonuri;

        if (!$huburi) {
            // We can only deal with folks with a PuSH hub
            throw new FeedSubNoHubException();
        }

        // Try to get a profile from the feed activity:subject

        $feedEl = $discover->feed->documentElement;

        $subject = ActivityUtils::child($feedEl, Activity::SUBJECT, Activity::SPEC);

        if (!empty($subject)) {
            $subjObject = new ActivityObject($subject);
            return self::ensureActivityObjectProfile($subjObject, $hints);
        }

        // Otherwise, try the feed author

        $author = ActivityUtils::child($feedEl, Activity::AUTHOR, Activity::ATOM);

        if (!empty($author)) {
            $authorObject = new ActivityObject($author);
            return self::ensureActivityObjectProfile($authorObject, $hints);
        }

        // Sheesh. Not a very nice feed! Let's try fingerpoken in the
        // entries.

        $entries = $discover->feed->getElementsByTagNameNS(Activity::ATOM, 'entry');

        if (!empty($entries) && $entries->length > 0) {

            $entry = $entries->item(0);

            $actor = ActivityUtils::child($entry, Activity::ACTOR, Activity::SPEC);

            if (!empty($actor)) {
                $actorObject = new ActivityObject($actor);
                return self::ensureActivityObjectProfile($actorObject, $hints);

            }

            $author = ActivityUtils::child($entry, Activity::AUTHOR, Activity::ATOM);

            if (!empty($author)) {
                $authorObject = new ActivityObject($author);
                return self::ensureActivityObjectProfile($authorObject, $hints);
            }
        }

        // XXX: make some educated guesses here

        throw new FeedSubException("Can't find enough profile information to make a feed.");
    }

    /**
     *
     * Download and update given avatar image
     * @param string $url
     * @throws Exception in various failure cases
     */
    protected function updateAvatar($url)
    {
        if ($url == $this->avatar) {
            // We've already got this one.
            return;
        }

        if ($this->isGroup()) {
            $self = $this->localGroup();
        } else {
            $self = $this->localProfile();
        }
        if (!$self) {
            throw new ServerException(sprintf(
                _m("Tried to update avatar for unsaved remote profile %s"),
                $this->uri));
        }

        // @fixme this should be better encapsulated
        // ripped from oauthstore.php (for old OMB client)
        $temp_filename = tempnam(sys_get_temp_dir(), 'listener_avatar');
        if (!copy($url, $temp_filename)) {
            throw new ServerException(sprintf(_m("Unable to fetch avatar from %s"), $url));
        }

        if ($this->isGroup()) {
            $id = $this->group_id;
        } else {
            $id = $this->profile_id;
        }
        // @fixme should we be using different ids?
        $imagefile = new ImageFile($id, $temp_filename);
        $filename = Avatar::filename($id,
                                     image_type_to_extension($imagefile->type),
                                     null,
                                     common_timestamp());
        rename($temp_filename, Avatar::path($filename));
        $self->setOriginal($filename);

        $orig = clone($this);
        $this->avatar = $url;
        $this->update($orig);
    }

    /**
     * Pull avatar URL from ActivityObject or profile hints
     *
     * @param ActivityObject $object
     * @param array $hints
     * @return mixed URL string or false
     */

    protected static function getActivityObjectAvatar($object, $hints=array())
    {
        if ($object->avatarLinks) {
            $best = false;
            // Take the exact-size avatar, or the largest avatar, or the first avatar if all sizeless
            foreach ($object->avatarLinks as $avatar) {
                if ($avatar->width == AVATAR_PROFILE_SIZE && $avatar->height = AVATAR_PROFILE_SIZE) {
                    // Exact match!
                    $best = $avatar;
                    break;
                }
                if (!$best || $avatar->width > $best->width) {
                    $best = $avatar;
                }
            }
            return $best->url;
        } else if (array_key_exists('avatar', $hints)) {
            return $hints['avatar'];
        }
        return false;
    }

    /**
     * Get an appropriate avatar image source URL, if available.
     *
     * @param ActivityObject $actor
     * @param DOMElement $feed
     * @return string
     */

    protected static function getAvatar($actor, $feed)
    {
        $url = '';
        $icon = '';
        if ($actor->avatar) {
            $url = trim($actor->avatar);
        }
        if (!$url) {
            // Check <atom:logo> and <atom:icon> on the feed
            $els = $feed->childNodes();
            if ($els && $els->length) {
                for ($i = 0; $i < $els->length; $i++) {
                    $el = $els->item($i);
                    if ($el->namespaceURI == Activity::ATOM) {
                        if (empty($url) && $el->localName == 'logo') {
                            $url = trim($el->textContent);
                            break;
                        }
                        if (empty($icon) && $el->localName == 'icon') {
                            // Use as a fallback
                            $icon = trim($el->textContent);
                        }
                    }
                }
            }
            if ($icon && !$url) {
                $url = $icon;
            }
        }
        if ($url) {
            $opts = array('allowed_schemes' => array('http', 'https'));
            if (Validate::uri($url, $opts)) {
                return $url;
            }
        }
        return common_path('plugins/OStatus/images/96px-Feed-icon.svg.png');
    }

    /**
     * Fetch, or build if necessary, an Ostatus_profile for the actor
     * in a given Activity Streams activity.
     *
     * @param Activity $activity
     * @param string $feeduri if we already know the canonical feed URI!
     * @param string $salmonuri if we already know the salmon return channel URI
     * @return Ostatus_profile
     */

    public static function ensureActorProfile($activity, $hints=array())
    {
        return self::ensureActivityObjectProfile($activity->actor, $hints);
    }

    public static function ensureActivityObjectProfile($object, $hints=array())
    {
        $profile = self::getActivityObjectProfile($object);
        if ($profile) {
            $profile->updateFromActivityObject($object, $hints);
        } else {
            $profile = self::createActivityObjectProfile($object, $hints);
        }
        return $profile;
    }

    /**
     * @param Activity $activity
     * @return mixed matching Ostatus_profile or false if none known
     */
    public static function getActorProfile($activity)
    {
        return self::getActivityObjectProfile($activity->actor);
    }

    protected static function getActivityObjectProfile($object)
    {
        $uri = self::getActivityObjectProfileURI($object);
        return Ostatus_profile::staticGet('uri', $uri);
    }

    protected static function getActorProfileURI($activity)
    {
        return self::getActivityObjectProfileURI($activity->actor);
    }

    /**
     * @param Activity $activity
     * @return string
     * @throws ServerException
     */
    protected static function getActivityObjectProfileURI($object)
    {
        $opts = array('allowed_schemes' => array('http', 'https'));
        if ($object->id && Validate::uri($object->id, $opts)) {
            return $object->id;
        }
        if ($object->link && Validate::uri($object->link, $opts)) {
            return $object->link;
        }
        throw new ServerException("No author ID URI found");
    }

    /**
     * @fixme validate stuff somewhere
     */

    /**
     * Create local ostatus_profile and profile/user_group entries for
     * the provided remote user or group.
     *
     * @param ActivityObject $object
     * @param array $hints
     *
     * @return Ostatus_profile
     */
    protected static function createActivityObjectProfile($object, $hints=array())
    {
        $homeuri = $object->id;
        $discover = false;

        if (!$homeuri) {
            common_log(LOG_DEBUG, __METHOD__ . " empty actor profile URI: " . var_export($activity, true));
            throw new ServerException("No profile URI");
        }

        if (array_key_exists('feedurl', $hints)) {
            $feeduri = $hints['feedurl'];
        } else {
            $discover = new FeedDiscovery();
            $feeduri = $discover->discoverFromURL($homeuri);
        }

        if (array_key_exists('salmon', $hints)) {
            $salmonuri = $hints['salmon'];
        } else {
            if (!$discover) {
                $discover = new FeedDiscovery();
                $discover->discoverFromFeedURL($hints['feedurl']);
            }
            $salmonuri = $discover->getAtomLink(Salmon::NS_REPLIES);
        }

        if (array_key_exists('hub', $hints)) {
            $huburi = $hints['hub'];
        } else {
            if (!$discover) {
                $discover = new FeedDiscovery();
                $discover->discoverFromFeedURL($hints['feedurl']);
            }
            $huburi = $discover->getAtomLink('hub');
        }

        if (!$huburi) {
            // We can only deal with folks with a PuSH hub
            throw new FeedSubNoHubException();
        }

        $oprofile = new Ostatus_profile();

        $oprofile->uri        = $homeuri;
        $oprofile->feeduri    = $feeduri;
        $oprofile->salmonuri  = $salmonuri;

        $oprofile->created    = common_sql_now();
        $oprofile->modified   = common_sql_now();

        if ($object->type == ActivityObject::PERSON) {
            $profile = new Profile();
            $profile->created = common_sql_now();
            self::updateProfile($profile, $object, $hints);

            $oprofile->profile_id = $profile->insert();
            if (!$oprofile->profile_id) {
                throw new ServerException("Can't save local profile");
            }
        } else {
            $group = new User_group();
            $group->uri = $homeuri;
            $group->created = common_sql_now();
            self::updateGroup($group, $object, $hints);

            $oprofile->group_id = $group->insert();
            if (!$oprofile->group_id) {
                throw new ServerException("Can't save local profile");
            }
        }

        $ok = $oprofile->insert();

        if ($ok) {
            $avatar = self::getActivityObjectAvatar($object, $hints);
            if ($avatar) {
                $oprofile->updateAvatar($avatar);
            }
            return $oprofile;
        } else {
            throw new ServerException("Can't save OStatus profile");
        }
    }

    /**
     * Save any updated profile information to our local copy.
     * @param ActivityObject $object
     * @param array $hints
     */
    public function updateFromActivityObject($object, $hints=array())
    {
        if ($this->isGroup()) {
            $group = $this->localGroup();
            self::updateGroup($group, $object, $hints);
        } else {
            $profile = $this->localProfile();
            self::updateProfile($profile, $object, $hints);
        }
        $avatar = self::getActivityObjectAvatar($object, $hints);
        if ($avatar) {
            $this->updateAvatar($avatar);
        }
    }

    protected static function updateProfile($profile, $object, $hints=array())
    {
        $orig = clone($profile);

        $profile->nickname = self::getActivityObjectNickname($object, $hints);

        if (!empty($object->title)) {
            $profile->fullname = $object->title;
        } else if (array_key_exists('fullname', $hints)) {
            $profile->fullname = $hints['fullname'];
        }

        if (!empty($object->link)) {
            $profile->profileurl = $object->link;
        } else if (array_key_exists('profileurl', $hints)) {
            $profile->profileurl = $hints['profileurl'];
        } else if (Validate::uri($object->id, array('allowed_schemes' => array('http', 'https')))) {
            $profile->profileurl = $object->id;
        }

        $profile->bio      = self::getActivityObjectBio($object, $hints);
        $profile->location = self::getActivityObjectLocation($object, $hints);
        $profile->homepage = self::getActivityObjectHomepage($object, $hints);

        if (!empty($object->geopoint)) {
            $location = ActivityContext::locationFromPoint($object->geopoint);
            if (!empty($location)) {
                $profile->lat = $location->lat;
                $profile->lon = $location->lon;
            }
        }

        // @fixme tags/categories
        // @todo tags from categories

        if ($profile->id) {
            common_log(LOG_DEBUG, "Updating OStatus profile $profile->id from remote info $object->id: " . var_export($object, true) . var_export($hints, true));
            $profile->update($orig);
        }
    }

    protected static function updateGroup($group, $object, $hints=array())
    {
        $orig = clone($group);

        $group->nickname = self::getActivityObjectNickname($object, $hints);
        $group->fullname = $object->title;

        if (!empty($object->link)) {
            $group->mainpage = $object->link;
        } else if (array_key_exists('profileurl', $hints)) {
            $group->mainpage = $hints['profileurl'];
        }

        // @todo tags from categories
        $group->description = self::getActivityObjectBio($object, $hints);
        $group->location = self::getActivityObjectLocation($object, $hints);
        $group->homepage = self::getActivityObjectHomepage($object, $hints);

        if ($group->id) {
            common_log(LOG_DEBUG, "Updating OStatus group $group->id from remote info $object->id: " . var_export($object, true) . var_export($hints, true));
            $group->update($orig);
        }
    }

    protected static function getActivityObjectHomepage($object, $hints=array())
    {
        $homepage = null;
        $poco     = $object->poco;

        if (!empty($poco)) {
            $url = $poco->getPrimaryURL();
            if ($url && $url->type == 'homepage') {
                $homepage = $url->value;
            }
        }

        // @todo Try for a another PoCo URL?

        return $homepage;
    }

    protected static function getActivityObjectLocation($object, $hints=array())
    {
        $location = null;

        if (!empty($object->poco) &&
            isset($object->poco->address->formatted)) {
            $location = $object->poco->address->formatted;
        } else if (array_key_exists('location', $hints)) {
            $location = $hints['location'];
        }

        if (!empty($location)) {
            if (mb_strlen($location) > 255) {
                $location = mb_substr($note, 0, 255 - 3) . ' … ';
            }
        }

        // @todo Try to find location some othe way? Via goerss point?

        return $location;
    }

    protected static function getActivityObjectBio($object, $hints=array())
    {
        $bio  = null;

        if (!empty($object->poco)) {
            $note = $object->poco->note;
        } else if (array_key_exists('bio', $hints)) {
            $note = $hints['bio'];
        }

        if (!empty($note)) {
            if (Profile::bioTooLong($note)) {
                // XXX: truncate ok?
                $bio = mb_substr($note, 0, Profile::maxBio() - 3) . ' … ';
            } else {
                $bio = $note;
            }
        }

        // @todo Try to get bio info some other way?

        return $bio;
    }

    protected static function getActivityObjectNickname($object, $hints=array())
    {
        if ($object->poco) {
            if (!empty($object->poco->preferredUsername)) {
                return common_nicknamize($object->poco->preferredUsername);
            }
        }

        if (!empty($object->nickname)) {
            return common_nicknamize($object->nickname);
        }

        if (array_key_exists('nickname', $hints)) {
            return $hints['nickname'];
        }

        // Try the definitive ID

        $nickname = self::nicknameFromURI($object->id);

        // Try a Webfinger if one was passed (way) down

        if (empty($nickname)) {
            if (array_key_exists('webfinger', $hints)) {
                $nickname = self::nicknameFromURI($hints['webfinger']);
            }
        }

        // Try the name

        if (empty($nickname)) {
            $nickname = common_nicknamize($object->title);
        }

        return $nickname;
    }

    protected static function nicknameFromURI($uri)
    {
        preg_match('/(\w+):/', $uri, $matches);

        $protocol = $matches[1];

        switch ($protocol) {
        case 'acct':
        case 'mailto':
            if (preg_match("/^$protocol:(.*)?@.*\$/", $uri, $matches)) {
                return common_canonical_nickname($matches[1]);
            }
            return null;
        case 'http':
            return common_url_to_nickname($uri);
            break;
        default:
            return null;
        }
    }

    /**
     * @param string $addr webfinger address
     * @return Ostatus_profile
     * @throws Exception on error conditions
     */
    public static function ensureWebfinger($addr)
    {
        // First, try the cache

        $uri = self::cacheGet(sprintf('ostatus_profile:webfinger:%s', $addr));

        if ($uri !== false) {
            if (is_null($uri)) {
                // Negative cache entry
                throw new Exception('Not a valid webfinger address.');
            }
            $oprofile = Ostatus_profile::staticGet('uri', $uri);
            if (!empty($oprofile)) {
                return $oprofile;
            }
        }

        // First, look it up

        $oprofile = Ostatus_profile::staticGet('uri', 'acct:'.$addr);

        if (!empty($oprofile)) {
            self::cacheSet(sprintf('ostatus_profile:webfinger:%s', $addr), $oprofile->uri);
            return $oprofile;
        }

        // Now, try some discovery

        $disco = new Discovery();

        try {
            $result = $disco->lookup($addr);
        } catch (Exception $e) {
            // Save negative cache entry so we don't waste time looking it up again.
            // @fixme distinguish temporary failures?
            self::cacheSet(sprintf('ostatus_profile:webfinger:%s', $addr), null);
            throw new Exception('Not a valid webfinger address.');
        }

        $hints = array('webfinger' => $addr);

        foreach ($result->links as $link) {
            switch ($link['rel']) {
            case Discovery::PROFILEPAGE:
                $hints['profileurl'] = $profileUrl = $link['href'];
                break;
            case Salmon::NS_REPLIES:
                $hints['salmon'] = $salmonEndpoint = $link['href'];
                break;
            case Discovery::UPDATESFROM:
                $hints['feedurl'] = $feedUrl = $link['href'];
                break;
            case Discovery::HCARD:
                $hcardUrl = $link['href'];
                break;
            default:
                common_log(LOG_NOTICE, "Don't know what to do with rel = '{$link['rel']}'");
                break;
            }
        }

        if (isset($hcardUrl)) {
            $hcardHints = self::slurpHcard($hcardUrl);
            // Note: Webfinger > hcard
            $hints = array_merge($hcardHints, $hints);
        }

        // If we got a feed URL, try that

        if (isset($feedUrl)) {
            try {
                common_log(LOG_INFO, "Discovery on acct:$addr with feed URL $feedUrl");
                $oprofile = self::ensureProfile($feedUrl, $hints);
                self::cacheSet(sprintf('ostatus_profile:webfinger:%s', $addr), $oprofile->uri);
                return $oprofile;
            } catch (Exception $e) {
                common_log(LOG_WARNING, "Failed creating profile from feed URL '$feedUrl': " . $e->getMessage());
                // keep looking
            }
        }

        // If we got a profile page, try that!

        if (isset($profileUrl)) {
            try {
                common_log(LOG_INFO, "Discovery on acct:$addr with profile URL $profileUrl");
                $oprofile = self::ensureProfile($profileUrl, $hints);
                self::cacheSet(sprintf('ostatus_profile:webfinger:%s', $addr), $oprofile->uri);
                return $oprofile;
            } catch (Exception $e) {
                common_log(LOG_WARNING, "Failed creating profile from profile URL '$profileUrl': " . $e->getMessage());
                // keep looking
            }
        }

        // XXX: try hcard
        // XXX: try FOAF

        if (isset($salmonEndpoint)) {

            // An account URL, a salmon endpoint, and a dream? Not much to go
            // on, but let's give it a try

            $uri = 'acct:'.$addr;

            $profile = new Profile();

            $profile->nickname = self::nicknameFromUri($uri);
            $profile->created  = common_sql_now();

            if (isset($profileUrl)) {
                $profile->profileurl = $profileUrl;
            }

            $profile_id = $profile->insert();

            if (!$profile_id) {
                common_log_db_error($profile, 'INSERT', __FILE__);
                throw new Exception("Couldn't save profile for '$addr'");
            }

            $oprofile = new Ostatus_profile();

            $oprofile->uri        = $uri;
            $oprofile->salmonuri  = $salmonEndpoint;
            $oprofile->profile_id = $profile_id;
            $oprofile->created    = common_sql_now();

            if (isset($feedUrl)) {
                $profile->feeduri = $feedUrl;
            }

            $result = $oprofile->insert();

            if (!$result) {
                common_log_db_error($oprofile, 'INSERT', __FILE__);
                throw new Exception("Couldn't save ostatus_profile for '$addr'");
            }

            self::cacheSet(sprintf('ostatus_profile:webfinger:%s', $addr), $oprofile->uri);
            return $oprofile;
        }

        throw new Exception("Couldn't find a valid profile for '$addr'");
    }

    function saveHTMLFile($title, $rendered)
    {
        $final = sprintf("<!DOCTYPE html>\n<html><head><title>%s</title></head>".
                         '<body><div>%s</div></body></html>',
                         htmlspecialchars($title),
                         $rendered);

        $filename = File::filename($this->localProfile(),
                                   'ostatus', // ignored?
                                   'text/html');

        $filepath = File::path($filename);

        file_put_contents($filepath, $final);

        $file = new File;

        $file->filename = $filename;
        $file->url      = File::url($filename);
        $file->size     = filesize($filepath);
        $file->date     = time();
        $file->mimetype = 'text/html';

        $file_id = $file->insert();

        if ($file_id === false) {
            common_log_db_error($file, "INSERT", __FILE__);
            throw new ServerException(_('Could not store HTML content of long post as file.'));
        }

        return $file;
    }

    protected static function slurpHcard($url)
    {
        set_include_path(get_include_path() . PATH_SEPARATOR . INSTALLDIR . '/plugins/OStatus/extlib/hkit/');
        require_once('hkit.class.php');

        $h	= new hKit;

        // Google Buzz hcards need to be tidied. Probably others too.

        $h->tidy_mode = 'proxy'; // 'proxy', 'exec', 'php' or 'none'

        // Get by URL
        $hcards = $h->getByURL('hcard', $url);

        if (empty($hcards)) {
            return array();
        }

        // @fixme more intelligent guess on multi-hcard pages
        $hcard = $hcards[0];

        $hints = array();

        $hints['profileurl'] = $url;

        if (array_key_exists('nickname', $hcard)) {
            $hints['nickname'] = $hcard['nickname'];
        }

        if (array_key_exists('fn', $hcard)) {
            $hints['fullname'] = $hcard['fn'];
        } else if (array_key_exists('n', $hcard)) {
            $hints['fullname'] = implode(' ', $hcard['n']);
        }

        if (array_key_exists('photo', $hcard)) {
            $hints['avatar'] = $hcard['photo'];
        }

        if (array_key_exists('note', $hcard)) {
            $hints['bio'] = $hcard['note'];
        }

        if (array_key_exists('adr', $hcard)) {
            if (is_string($hcard['adr'])) {
                $hints['location'] = $hcard['adr'];
            } else if (is_array($hcard['adr'])) {
                $hints['location'] = implode(' ', $hcard['adr']);
            }
        }

        if (array_key_exists('url', $hcard)) {
            if (is_string($hcard['url'])) {
                $hints['homepage'] = $hcard['url'];
            } else if (is_array($hcard['url'])) {
                // HACK get the last one; that's how our hcards look
                $hints['homepage'] = $hcard['url'][count($hcard['url'])-1];
            }
        }

        return $hints;
    }
}
