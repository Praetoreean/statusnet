<?php
/*
 * StatusNet - the distributed open-source microblogging tool
 * Copyright (C) 2008, 2009, StatusNet, Inc.
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

if (!defined('STATUSNET') && !defined('LACONICA')) { exit(1); }

/**
 * Table Definition for profile
 */
require_once INSTALLDIR.'/classes/Memcached_DataObject.php';

class Profile extends Memcached_DataObject
{
    ###START_AUTOCODE
    /* the code below is auto generated do not remove the above tag */

    public $__table = 'profile';                         // table name
    public $id;                              // int(4)  primary_key not_null
    public $nickname;                        // varchar(64)  multiple_key not_null
    public $fullname;                        // varchar(255)  multiple_key
    public $profileurl;                      // varchar(255)
    public $homepage;                        // varchar(255)  multiple_key
    public $bio;                             // text()  multiple_key
    public $location;                        // varchar(255)  multiple_key
    public $lat;                             // decimal(10,7)
    public $lon;                             // decimal(10,7)
    public $location_id;                     // int(4)
    public $location_ns;                     // int(4)
    public $created;                         // datetime()   not_null
    public $modified;                        // timestamp()   not_null default_CURRENT_TIMESTAMP

    /* Static get */
    function staticGet($k,$v=NULL) {
        return Memcached_DataObject::staticGet('Profile',$k,$v);
    }

    /* the code above is auto generated do not remove the tag below */
    ###END_AUTOCODE

    function getUser()
    {
        return User::staticGet('id', $this->id);
    }

    function getAvatar($width, $height=null)
    {
        if (is_null($height)) {
            $height = $width;
        }
        return Avatar::pkeyGet(array('profile_id' => $this->id,
                                     'width' => $width,
                                     'height' => $height));
    }

    function getOriginalAvatar()
    {
        $avatar = DB_DataObject::factory('avatar');
        $avatar->profile_id = $this->id;
        $avatar->original = true;
        if ($avatar->find(true)) {
            return $avatar;
        } else {
            return null;
        }
    }

    function setOriginal($filename)
    {
        $imagefile = new ImageFile($this->id, Avatar::path($filename));

        $avatar = new Avatar();
        $avatar->profile_id = $this->id;
        $avatar->width = $imagefile->width;
        $avatar->height = $imagefile->height;
        $avatar->mediatype = image_type_to_mime_type($imagefile->type);
        $avatar->filename = $filename;
        $avatar->original = true;
        $avatar->url = Avatar::url($filename);
        $avatar->created = DB_DataObject_Cast::dateTime(); # current time

        # XXX: start a transaction here

        if (!$this->delete_avatars() || !$avatar->insert()) {
            @unlink(Avatar::path($filename));
            return null;
        }

        foreach (array(AVATAR_PROFILE_SIZE, AVATAR_STREAM_SIZE, AVATAR_MINI_SIZE) as $size) {
            # We don't do a scaled one if original is our scaled size
            if (!($avatar->width == $size && $avatar->height == $size)) {

                $scaled_filename = $imagefile->resize($size);

                //$scaled = DB_DataObject::factory('avatar');
                $scaled = new Avatar();
                $scaled->profile_id = $this->id;
                $scaled->width = $size;
                $scaled->height = $size;
                $scaled->original = false;
                $scaled->mediatype = image_type_to_mime_type($imagefile->type);
                $scaled->filename = $scaled_filename;
                $scaled->url = Avatar::url($scaled_filename);
                $scaled->created = DB_DataObject_Cast::dateTime(); # current time

                if (!$scaled->insert()) {
                    return null;
                }
            }
        }

        return $avatar;
    }

    function delete_avatars($original=true)
    {
        $avatar = new Avatar();
        $avatar->profile_id = $this->id;
        $avatar->find();
        while ($avatar->fetch()) {
            if ($avatar->original) {
                if ($original == false) {
                    continue;
                }
            }
            $avatar->delete();
        }
        return true;
    }

    function getBestName()
    {
        return ($this->fullname) ? $this->fullname : $this->nickname;
    }

    # Get latest notice on or before date; default now
    function getCurrentNotice($dt=null)
    {
        $notice = new Notice();
        $notice->profile_id = $this->id;
        if ($dt) {
            $notice->whereAdd('created < "' . $dt . '"');
        }
        $notice->orderBy('created DESC, notice.id DESC');
        $notice->limit(1);
        if ($notice->find(true)) {
            return $notice;
        }
        return null;
    }

    function getTaggedNotices($tag, $offset=0, $limit=NOTICES_PER_PAGE, $since_id=0, $max_id=0, $since=null)
    {
        $ids = Notice::stream(array($this, '_streamTaggedDirect'),
                              array($tag),
                              'profile:notice_ids_tagged:' . $this->id . ':' . $tag,
                              $offset, $limit, $since_id, $max_id, $since);
        return Notice::getStreamByIds($ids);
    }

    function getNotices($offset=0, $limit=NOTICES_PER_PAGE, $since_id=0, $max_id=0, $since=null)
    {
        // XXX: I'm not sure this is going to be any faster. It probably isn't.
        $ids = Notice::stream(array($this, '_streamDirect'),
                              array(),
                              'profile:notice_ids:' . $this->id,
                              $offset, $limit, $since_id, $max_id, $since);

        return Notice::getStreamByIds($ids);
    }

    function _streamTaggedDirect($tag, $offset, $limit, $since_id, $max_id, $since)
    {
        // XXX It would be nice to do this without a join

        $notice = new Notice();

        $query =
          "select id from notice join notice_tag on id=notice_id where tag='".
          $notice->escape($tag) .
          "' and profile_id=" . $notice->escape($this->id);

        if ($since_id != 0) {
            $query .= " and id > $since_id";
        }

        if ($max_id != 0) {
            $query .= " and id < $max_id";
        }

        if (!is_null($since)) {
            $query .= " and created > '" . date('Y-m-d H:i:s', $since) . "'";
        }

        $query .= ' order by id DESC';

        if (!is_null($offset)) {
            $query .= " LIMIT $limit OFFSET $offset";
        }

        $notice->query($query);

        $ids = array();

        while ($notice->fetch()) {
            $ids[] = $notice->id;
        }

        return $ids;
    }

    function _streamDirect($offset, $limit, $since_id, $max_id, $since = null)
    {
        $notice = new Notice();

        $notice->profile_id = $this->id;

        $notice->selectAdd();
        $notice->selectAdd('id');

        if ($since_id != 0) {
            $notice->whereAdd('id > ' . $since_id);
        }

        if ($max_id != 0) {
            $notice->whereAdd('id <= ' . $max_id);
        }

        if (!is_null($since)) {
            $notice->whereAdd('created > \'' . date('Y-m-d H:i:s', $since) . '\'');
        }

        $notice->orderBy('id DESC');

        if (!is_null($offset)) {
            $notice->limit($offset, $limit);
        }

        $ids = array();

        if ($notice->find()) {
            while ($notice->fetch()) {
                $ids[] = $notice->id;
            }
        }

        return $ids;
    }

    function isMember($group)
    {
        $mem = new Group_member();

        $mem->group_id = $group->id;
        $mem->profile_id = $this->id;

        if ($mem->find()) {
            return true;
        } else {
            return false;
        }
    }

    function isAdmin($group)
    {
        $mem = new Group_member();

        $mem->group_id = $group->id;
        $mem->profile_id = $this->id;
        $mem->is_admin = 1;

        if ($mem->find()) {
            return true;
        } else {
            return false;
        }
    }

    function avatarUrl($size=AVATAR_PROFILE_SIZE)
    {
        $avatar = $this->getAvatar($size);
        if ($avatar) {
            return $avatar->displayUrl();
        } else {
            return Avatar::defaultImage($size);
        }
    }

    function getSubscriptions($offset=0, $limit=null)
    {
        $qry =
          'SELECT profile.* ' .
          'FROM profile JOIN subscription ' .
          'ON profile.id = subscription.subscribed ' .
          'WHERE subscription.subscriber = %d ' .
          'AND subscription.subscribed != subscription.subscriber ' .
          'ORDER BY subscription.created DESC ';

        if ($offset>0 && !is_null($limit)){
            if (common_config('db','type') == 'pgsql') {
                $qry .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
            } else {
                $qry .= ' LIMIT ' . $offset . ', ' . $limit;
            }
        }

        $profile = new Profile();

        $profile->query(sprintf($qry, $this->id));

        return $profile;
    }

    function getSubscribers($offset=0, $limit=null)
    {
        $qry =
          'SELECT profile.* ' .
          'FROM profile JOIN subscription ' .
          'ON profile.id = subscription.subscriber ' .
          'WHERE subscription.subscribed = %d ' .
          'AND subscription.subscribed != subscription.subscriber ' .
          'ORDER BY subscription.created DESC ';

        if ($offset>0 && !is_null($limit)){
            if ($offset) {
                if (common_config('db','type') == 'pgsql') {
                    $qry .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
                } else {
                    $qry .= ' LIMIT ' . $offset . ', ' . $limit;
                }
            }
        }

        $profile = new Profile();

        $cnt = $profile->query(sprintf($qry, $this->id));

        return $profile;
    }

    function subscriptionCount()
    {
        $c = common_memcache();

        if (!empty($c)) {
            $cnt = $c->get(common_cache_key('profile:subscription_count:'.$this->id));
            if (is_integer($cnt)) {
                return (int) $cnt;
            }
        }

        $sub = new Subscription();
        $sub->subscriber = $this->id;

        $cnt = (int) $sub->count('distinct subscribed');

        $cnt = ($cnt > 0) ? $cnt - 1 : $cnt;

        if (!empty($c)) {
            $c->set(common_cache_key('profile:subscription_count:'.$this->id), $cnt);
        }

        return $cnt;
    }

    function subscriberCount()
    {
        $c = common_memcache();
        if (!empty($c)) {
            $cnt = $c->get(common_cache_key('profile:subscriber_count:'.$this->id));
            if (is_integer($cnt)) {
                return (int) $cnt;
            }
        }

        $sub = new Subscription();
        $sub->subscribed = $this->id;

        $cnt = (int) $sub->count('distinct subscriber');

        $cnt = ($cnt > 0) ? $cnt - 1 : $cnt;

        if (!empty($c)) {
            $c->set(common_cache_key('profile:subscriber_count:'.$this->id), $cnt);
        }

        return $cnt;
    }

    function faveCount()
    {
        $c = common_memcache();
        if (!empty($c)) {
            $cnt = $c->get(common_cache_key('profile:fave_count:'.$this->id));
            if (is_integer($cnt)) {
                return (int) $cnt;
            }
        }

        $faves = new Fave();
        $faves->user_id = $this->id;
        $cnt = (int) $faves->count('distinct notice_id');

        if (!empty($c)) {
            $c->set(common_cache_key('profile:fave_count:'.$this->id), $cnt);
        }

        return $cnt;
    }

    function noticeCount()
    {
        $c = common_memcache();

        if (!empty($c)) {
            $cnt = $c->get(common_cache_key('profile:notice_count:'.$this->id));
            if (is_integer($cnt)) {
                return (int) $cnt;
            }
        }

        $notices = new Notice();
        $notices->profile_id = $this->id;
        $cnt = (int) $notices->count('distinct id');

        if (!empty($c)) {
            $c->set(common_cache_key('profile:notice_count:'.$this->id), $cnt);
        }

        return $cnt;
    }

    function blowSubscriberCount()
    {
        $c = common_memcache();
        if (!empty($c)) {
            $c->delete(common_cache_key('profile:subscriber_count:'.$this->id));
        }
    }

    function blowSubscriptionCount()
    {
        $c = common_memcache();
        if (!empty($c)) {
            $c->delete(common_cache_key('profile:subscription_count:'.$this->id));
        }
    }

    function blowFaveCount()
    {
        $c = common_memcache();
        if (!empty($c)) {
            $c->delete(common_cache_key('profile:fave_count:'.$this->id));
        }
    }

    function blowNoticeCount()
    {
        $c = common_memcache();
        if (!empty($c)) {
            $c->delete(common_cache_key('profile:notice_count:'.$this->id));
        }
    }

    static function maxBio()
    {
        $biolimit = common_config('profile', 'biolimit');
        // null => use global limit (distinct from 0!)
        if (is_null($biolimit)) {
            $biolimit = common_config('site', 'textlimit');
        }
        return $biolimit;
    }

    static function bioTooLong($bio)
    {
        $biolimit = self::maxBio();
        return ($biolimit > 0 && !empty($bio) && (mb_strlen($bio) > $biolimit));
    }

    function delete()
    {
        $this->_deleteNotices();
        $this->_deleteSubscriptions();
        $this->_deleteMessages();
        $this->_deleteTags();
        $this->_deleteBlocks();

        $related = array('Avatar',
                         'Reply',
                         'Group_member',
                         );

        foreach ($related as $cls) {
            $inst = new $cls();
            $inst->profile_id = $this->id;
            $inst->delete();
        }

        parent::delete();
    }

    function _deleteNotices()
    {
        $notice = new Notice();
        $notice->profile_id = $this->id;

        if ($notice->find()) {
            while ($notice->fetch()) {
                $other = clone($notice);
                $other->delete();
            }
        }
    }

    function _deleteSubscriptions()
    {
        $sub = new Subscription();
        $sub->subscriber = $this->id;
        $sub->delete();

        $subd = new Subscription();
        $subd->subscribed = $this->id;
        $subd->delete();
    }

    function _deleteMessages()
    {
        $msg = new Message();
        $msg->from_profile = $this->id;
        $msg->delete();

        $msg = new Message();
        $msg->to_profile = $this->id;
        $msg->delete();
    }

    function _deleteTags()
    {
        $tag = new Profile_tag();
        $tag->tagged = $this->id;
        $tag->delete();
    }

    function _deleteBlocks()
    {
        $block = new Profile_block();
        $block->blocked = $this->id;
        $block->delete();

        $block = new Group_block();
        $block->blocked = $this->id;
        $block->delete();
    }

    // XXX: identical to Notice::getLocation.

    function getLocation()
    {
        $location = null;

        if (!empty($this->location_id) && !empty($this->location_ns)) {
            $location = Location::fromId($this->location_id, $this->location_ns);
        }

        if (is_null($location)) { // no ID, or Location::fromId() failed
            if (!empty($this->lat) && !empty($this->lon)) {
                $location = Location::fromLatLon($this->lat, $this->lon);
            }
        }

        if (is_null($location)) { // still haven't found it!
            if (!empty($this->location)) {
                $location = Location::fromName($this->location);
            }
        }

        return $location;
    }

    function hasRole($name)
    {
        $has_role = false;
        if (Event::handle('StartHasRole', array($this, $name, &$has_role))) {
            $role = Profile_role::pkeyGet(array('profile_id' => $this->id,
                                                'role' => $name));
            $has_role = !empty($role);
            Event::handle('EndHasRole', array($this, $name, $has_role));
        }
        return $has_role;
    }

    function grantRole($name)
    {
        $role = new Profile_role();

        $role->profile_id = $this->id;
        $role->role       = $name;
        $role->created    = common_sql_now();

        $result = $role->insert();

        if (!$result) {
            common_log_db_error($role, 'INSERT', __FILE__);
            return false;
        }

        return true;
    }

    function revokeRole($name)
    {
        $role = Profile_role::pkeyGet(array('profile_id' => $this->id,
                                            'role' => $name));

        if (empty($role)) {
            throw new Exception('Cannot revoke role "'.$name.'" for user #'.$this->id.'; does not exist.');
        }

        $result = $role->delete();

        if (!$result) {
            common_log_db_error($role, 'DELETE', __FILE__);
            throw new Exception('Cannot revoke role "'.$name.'" for user #'.$this->id.'; database error.');
        }

        return true;
    }

    function isSandboxed()
    {
        return $this->hasRole(Profile_role::SANDBOXED);
    }

    function isSilenced()
    {
        return $this->hasRole(Profile_role::SILENCED);
    }

    function sandbox()
    {
        $this->grantRole(Profile_role::SANDBOXED);
    }

    function unsandbox()
    {
        $this->revokeRole(Profile_role::SANDBOXED);
    }

    function silence()
    {
        $this->grantRole(Profile_role::SILENCED);
    }

    function unsilence()
    {
        $this->revokeRole(Profile_role::SILENCED);
    }

    /**
     * Does this user have the right to do X?
     *
     * With our role-based authorization, this is merely a lookup for whether the user
     * has a particular role. The implementation currently uses a switch statement
     * to determine if the user has the pre-defined role to exercise the right. Future
     * implementations may allow per-site roles, and different mappings of roles to rights.
     *
     * @param $right string Name of the right, usually a constant in class Right
     * @return boolean whether the user has the right in question
     */

    function hasRight($right)
    {
        $result = false;
        if (Event::handle('UserRightsCheck', array($this, $right, &$result))) {
            switch ($right)
            {
            case Right::DELETEOTHERSNOTICE:
            case Right::SANDBOXUSER:
            case Right::SILENCEUSER:
            case Right::DELETEUSER:
                $result = $this->hasRole(Profile_role::MODERATOR);
                break;
            case Right::CONFIGURESITE:
                $result = $this->hasRole(Profile_role::ADMINISTRATOR);
                break;
            case Right::NEWNOTICE:
            case Right::NEWMESSAGE:
            case Right::SUBSCRIBE:
                $result = !$this->isSilenced();
                break;
            case Right::PUBLICNOTICE:
            case Right::EMAILONREPLY:
            case Right::EMAILONSUBSCRIBE:
            case Right::EMAILONFAVE:
                $result = !$this->isSandboxed();
                break;
            default:
                $result = false;
                break;
            }
        }
        return $result;
    }

    function hasRepeated($notice_id)
    {
        // XXX: not really a pkey, but should work

        $notice = Memcached_DataObject::pkeyGet('Notice',
                                                array('profile_id' => $this->id,
                                                      'repeat_of' => $notice_id));

        return !empty($notice);
    }
}
