<?php
/*
 * Laconica - a distributed open-source microblogging tool
 * Copyright (C) 2008, Controlez-Vous, Inc.
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

if (!defined('LACONICA')) { exit(1); }

require_once(INSTALLDIR.'/lib/twitterapi.php');

class TwitapifavoritesAction extends TwitterapiAction {

	function is_readonly() {

		static $write_methods = array('favorites');

		$cmdtext = explode('.', $this->arg('method'));

		if (in_array($cmdtext[0], $write_methods)) {
			return false;
		}

		return true;
	}

	function favorites($args, $apidata) {
		parent::handle($args);

		$user = null;

		// function was called with an argument /favorites/api_arg.format
		if (isset($apidata['api_arg'])) {

			if (is_numeric($apidata['api_arg'])) {
				$user = User::staticGet($apidata['api_arg']);
			} else {
				$nickname = common_canonical_nickname($apidata['api_arg']);
				$user = User::staticGet('nickname', $nickname);
			}
		} else {

			// if no user was specified, then we'll use the authenticated user
			$user = $apidata['user'];
		}

		if (!$user) {
			// Set the user to be the auth user if asked-for can't be found
			// honestly! This is what Twitter does, I swear --Zach
			$user = $apidata['user'];
		}

		$profile = $user->getProfile();

		if (!$profile) {
			common_server_error(_('User has no profile.'));
			exit();
		}

		$page = $this->arg('page');

		if (!$page) {
			$page = 1;
		}

		if (!$count) {
			$count = 20;
		}

		$notice = $user->favoriteNotices((($page-1)*20), $count);

		if (!$notice) {
			common_server_error(_('Could not retrieve favorite notices.'));
			exit();
		}

		$sitename = common_config('site', 'name');
		$siteserver = common_config('site', 'server');

		$title = sprintf(_('%s / Favorites from %s'), $sitename, $user->nickname);
		$id = "tag:$siteserver:favorites:".$user->id;
		$link = common_local_url('favorites', array('nickname' => $user->nickname));
		$subtitle = sprintf(_('%s updates favorited by %s / %s.'), $sitename, $profile->getBestName(), $user->nickname);

		switch($apidata['content-type']) {
		 case 'xml':
			$this->show_xml_timeline($notice);
			break;
		 case 'rss':
			$this->show_rss_timeline($notice, $title, $id, $link, $subtitle);
			break;
		 case 'atom':
			$this->show_atom_timeline($notice, $title, $id, $link, $subtitle);
			break;
		 case 'json':
			$this->show_json_timeline($notice);
			break;
		 default:
			common_user_error(_('API method not found!'), $code = 404);
		}

		exit();
	}

	function create($args, $apidata) {
		parent::handle($args);

		if (!in_array($apidata['content-type'], array('xml', 'json'))) {
			common_user_error(_('API method not found!'), $code = 404);
			exit;
		}

		// Check for RESTfulness
		if (!in_array($_SERVER['REQUEST_METHOD'], array('POST', 'DELETE'))) {
			// XXX: Twitter just prints the err msg, no XML / JSON.
			$this->client_error(_('This method requires a POST or DELETE.'), 400, $apidata['content-type']);
			exit();
		}

		$user = $apidata['user'];
		$notice_id = $apidata['api_arg'];
		$notice = Notice::staticGet($notice_id);

		if (!$notice) {
			$this->client_error(_('No status found with that ID.'), 404, $apidata['content-type']);
			exit();
		}

		// XXX: Twitter lets you fave things repeatedly via api.
		if ($user->hasFave($notice)) {
			$this->client_error(_('This notice is already a favorite!'), 403, $apidata['content-type']);
			exit();
		}

		common_debug("notice: " . $apidata['api_arg']);

		$fave = Fave::addNew($user, $notice);

		if (!$fave) {
			common_server_error(_('Could not create favorite.'));
			exit();
		}

		$this->notify($fave, $notice, $user);
		$user->blowFavesCache();

		if ($apidata['content-type'] == 'xml') {
			$this->show_single_xml_status($notice);
		} elseif ($apidata['content-type'] == 'json') {
			$this->show_single_json_status($notice);
		}

		exit();
	}

	function destroy($args, $apidata) {
		parent::handle($args);
		common_server_error(_('API method under construction.'), $code=501);
		exit();
	}

	// XXX: these two funcs swiped from faves.  Maybe put in util.php, or some common base class?

	function notify($fave, $notice, $user) {
	    $other = User::staticGet('id', $notice->profile_id);
		if ($other && $other->id != $user->id) {
			if ($other->email && $other->emailnotifyfav) {
				$this->notify_mail($other, $user, $notice);
			}
			# XXX: notify by IM
			# XXX: notify by SMS
		}
	}

	function notify_mail($other, $user, $notice) {
		$profile = $user->getProfile();
		$bestname = $profile->getBestName();
		$subject = sprintf(_('%s added your notice as a favorite'), $bestname);
		$body = sprintf(_("%1\$s just added your notice from %2\$s as one of their favorites.\n\n" .
						  "In case you forgot, you can see the text of your notice here:\n\n" .
						  "%3\$s\n\n" .
						  "You can see the list of %1\$s's favorites here:\n\n" .
						  "%4\$s\n\n" .
						  "Faithfully yours,\n" .
						  "%5\$s\n"),
						$bestname,
						common_exact_date($notice->created),
						common_local_url('shownotice', array('notice' => $notice->id)),
						common_local_url('showfavorites', array('nickname' => $user->nickname)),
						common_config('site', 'name'));

		mail_to_user($other, $subject, $body);
	}

}