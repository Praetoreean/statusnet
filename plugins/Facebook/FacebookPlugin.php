<?php
/**
 * StatusNet, the distributed open-source microblogging tool
 *
 * Plugin to add a StatusNet Facebook application
 *
 * PHP version 5
 *
 * LICENCE: This program is free software: you can redistribute it and/or modify
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
 *
 * @category  Plugin
 * @package   StatusNet
 * @author    Zach Copley <zach@status.net>
 * @copyright 2009 StatusNet, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://status.net/
 */

if (!defined('STATUSNET')) {
    exit(1);
}

define("FACEBOOK_CONNECT_SERVICE", 3);

require_once INSTALLDIR . '/plugins/Facebook/facebookutil.php';

/**
 * Facebook plugin to add a StatusNet Facebook application
 *
 * @category Plugin
 * @package  StatusNet
 * @author   Zach Copley <zach@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link     http://status.net/
 */

class FacebookPlugin extends Plugin
{

    /**
     * Add Facebook app actions to the router table
     *
     * Hook for RouterInitialized event.
     *
     * @param Net_URL_Mapper &$m path-to-action mapper
     *
     * @return boolean hook return
     */

    function onStartInitializeRouter($m)
    {

        // Facebook App stuff

        $m->connect('facebook/app', array('action' => 'facebookhome'));
        $m->connect('facebook/app/index.php', array('action' => 'facebookhome'));
        $m->connect('facebook/app/settings.php',
                    array('action' => 'facebooksettings'));
        $m->connect('facebook/app/invite.php', array('action' => 'facebookinvite'));
        $m->connect('facebook/app/remove', array('action' => 'facebookremove'));

        // Facebook Connect stuff

        $m->connect('main/facebookconnect', array('action' => 'FBConnectAuth'));
        $m->connect('main/facebooklogin', array('action' => 'FBConnectLogin'));
        $m->connect('settings/facebook', array('action' => 'FBConnectSettings'));
        $m->connect('xd_receiver.html', array('action' => 'FBC_XDReceiver'));

        return true;
    }

    /**
     * Automatically load the actions and libraries used by the Facebook app
     *
     * @param Class $cls the class
     *
     * @return boolean hook return
     *
     */

    function onAutoload($cls)
    {
        switch ($cls) {
        case 'FacebookAction':
        case 'FacebookhomeAction':
        case 'FacebookinviteAction':
        case 'FacebookremoveAction':
        case 'FacebooksettingsAction':
            include_once INSTALLDIR . '/plugins/Facebook/' .
              strtolower(mb_substr($cls, 0, -6)) . '.php';
            return false;
        case 'FBConnectAuthAction':
        case 'FBConnectLoginAction':
        case 'FBConnectSettingsAction':
        case 'FBC_XDReceiverAction':
            include_once INSTALLDIR . '/plugins/Facebook/' .
              mb_substr($cls, 0, -6) . '.php';
            return false;
        case 'FBCLoginGroupNav':
            include_once INSTALLDIR . '/plugins/Facebook/FBCLoginGroupNav.php';
            return false;
        case 'FBCSettingsNav':
            include_once INSTALLDIR . '/plugins/Facebook/FBCSettingsNav.php';
            return false;
        default:
            return true;
        }
    }

    /**
     * Override normal HTML output to force the content type to
     * text/html and add in xmlns:fb
     *
     * @param Action $action the current action
     *
     * @return void
     */

    function onStartShowHTML($action)
    {

        if ($this->reqFbScripts($action)) {

            // XXX: Horrible hack to make Safari, FF2, and Chrome work with
            // Facebook Connect. These browser cannot use Facebook's
            // DOM parsing routines unless the mime type of the page is
            // text/html even though Facebook Connect uses XHTML.  This is
            // A bug in Facebook Connect, and this is a temporary solution
            // until they fix their JavaScript libs.

            header('Content-Type: text/html');

            $action->extraHeaders();

            $action->startXML('html');

            $language = $action->getLanguage();

            $action->elementStart('html',
                array('xmlns'  => 'http://www.w3.org/1999/xhtml',
                      'xmlns:fb' => 'http://www.facebook.com/2008/fbml',
                      'xml:lang' => $language,
                      'lang'     => $language));

            return false;

        } else {

            return true;
        }
    }

    /**
     * Add in the Facebook Connect JavaScript stuff
     *
     * Note: this script needs to appear in the <body>
     *
     * @param Action $action the current action
     *
     * @return void
     *
     */

    function onEndShowScripts($action)
    {
        if ($this->reqFbScripts($action)) {

            $apikey      = common_config('facebook', 'apikey');
            $plugin_path = common_path('plugins/Facebook');

            $login_url  = common_local_url('FBConnectAuth');
            $logout_url = common_local_url('logout');

            // XXX: Facebook says we don't need this FB_RequireFeatures(),
            // but we actually do, for IE and Safari. Gar.

            $js  = '<script type="text/javascript">';
            $js .= '    $(document).ready(function () {';
            $js .= '         FB_RequireFeatures(';
            $js .= '             ["XFBML"], function() {';
            $js .= '                 FB.init("%1$s", "../xd_receiver.html");';
            $js .= '             }';
            $js .= '         );';
            $js .= '    });';

            $js .= '    function goto_login() {';
            $js .= '        window.location = "%2$s";';
            $js .= '    }';

            // The below function alters the logout link so that it logs the user out
            // of Facebook Connect as well as the site.  However, for some pages
            // (FB Connect Settings) we need to output the FB Connect scripts (to
            // show an existing FB connection even if the user isn't authenticated
            // with Facebook connect) but NOT alter the logout link. And the only
            // way to reliably do that is with the FB Connect .js libs.  Crazy.

            $js .= '    FB.ensureInit(function() {';
            $js .= '        FB.Connect.ifUserConnected(';
            $js .= '            function() { ';
            $js .= '                $(\'#nav_logout a\').attr(\'href\', \'#\');';
            $js .= '                $(\'#nav_logout a\').click(function() {';
            $js .= '                   FB.Connect.logoutAndRedirect(\'%3$s\');';
            $js .= '                   return false;';
            $js .= '                })';
            $js .= '            },';
            $js .= '            function() {';
            $js .= '                return false;';
            $js .= '            }';
            $js .= '        );';
            $js .= '     });';
            $js .= '</script>';

            $js = sprintf($js, $apikey, $login_url, $logout_url);

            // Compress the bugger down a bit

            $js = str_replace('  ', '', $js);

            $action->raw("  $js");  // leading two spaces to make it line up
        }

    }

    /**
     * Add in an additional Facebook Connect script that's supposed to
     * appear as close as possible to </body>
     *
     * @param Action $action the current action
     *
     * @return void
     *
     */

    function onEndShowFooter($action)
    {
        if ($this->reqFbScripts($action)) {
            $action->script('http://static.ak.connect.facebook.com' .
                            '/js/api_lib/v0.4/FeatureLoader.js.php');
        }
    }

    /**
     * Output Facebook Connect specific CSS link
     *
     * @param Action $action the current action
     *
     * @return void
     *
     */

    function onEndShowStatusNetStyles($action)
    {
        if ($this->reqFbScripts($action)) {
            $action->cssLink('plugins/Facebook/FBConnect.css');
        }
    }

    /**
     * Does the Action we're plugged into require the FB Scripts?  We only
     * want to output FB namespace, scripts, CSS, etc. on the pages that
     * really need them.
     *
     * @param Action $action the current action
     *
     * @return boolean true
     */

    function reqFbScripts($action)
    {

        // If you're logged in w/FB Connect, you always need the FB stuff

        $fbuid = $this->loggedIn();

        if (!empty($fbuid)) {
            return true;
        }

        // List of actions that require FB stuff

        $needy = array('FBConnectLoginAction',
                       'FBConnectauthAction',
                       'FBConnectSettingsAction');

        if (in_array(get_class($action), $needy)) {
            return true;
        }

        return false;

    }

    /**
     * Is the user currently logged in with FB Connect?
     *
     * @return mixed $fbuid the Facebook ID of the logged in user, or null
     */

    function loggedIn()
    {
        $user = common_current_user();

        if (!empty($user)) {

            $flink = Foreign_link::getByUserId($user->id,
                FACEBOOK_CONNECT_SERVICE);
            $fbuid = 0;

            if (!empty($flink)) {

                try {

                    $facebook = getFacebook();
                    $fbuid    = $facebook->get_loggedin_user();

                } catch (Exception $e) {
                    common_log(LOG_WARNING, 'Facebook Connect Plugin - ' .
                        'Problem getting Facebook user: ' .
                            $e->getMessage());
                }

                if ($fbuid > 0) {
                    return $fbuid;
                }
            }
        }

        return null;
    }

    /**
     * Add in a Facebook Connect avatar to the primary nav menu
     *
     * @param Action $action the current action
     *
     * @return void
     *
     */

    function onStartPrimaryNav($action)
    {
        $user = common_current_user();

        $connect = 'FBConnectSettings';
        if (common_config('xmpp', 'enabled')) {
            $connect = 'imsettings';
        } else if (common_config('sms', 'enabled')) {
            $connect = 'smssettings';
        } else if (common_config('twitter', 'enabled')) {
            $connect = 'twittersettings';
        }

        if (!empty($user)) {

            $fbuid = $this->loggedIn();

            if (!empty($fbuid)) {

                /* Default FB silhouette pic for FB users who haven't
                   uploaded a profile pic yet. */

                $silhouetteUrl =
                    'http://static.ak.fbcdn.net/pics/q_silhouette.gif';

                $url = $this->getProfilePicURL($fbuid);

                $action->elementStart('li', array('id' => 'nav_fb'));

                $action->element('img', array('id' => 'fbc_profile-pic',
                    'src' => (!empty($url)) ? $url : $silhouetteUrl,
                    'alt' => 'Facebook Connect User',
                    'width' => '16'), '');

                $iconurl =  common_path('plugins/Facebook/fbfavicon.ico');
                $action->element('img', array('id' => 'fb_favicon',
                    'src' => $iconurl));

                $action->elementEnd('li');

            }
        }

        return true;
    }

    /**
     * Alter the local nav menu to have a Facebook Connect login and
     * settings pages
     *
     * @param Action $action the current action
     *
     * @return void
     *
     */

    function onStartShowLocalNavBlock($action)
    {
        $action_name = get_class($action);

        $login_actions = array('LoginAction', 'RegisterAction',
            'OpenidloginAction', 'FBConnectLoginAction');

        if (in_array($action_name, $login_actions)) {
            $nav = new FBCLoginGroupNav($action);
            $nav->show();
            return false;
        }

        $connect_actions = array('SmssettingsAction', 'ImsettingsAction',
            'TwittersettingsAction', 'FBConnectSettingsAction');

        if (in_array($action_name, $connect_actions)) {
            $nav = new FBCSettingsNav($action);
            $nav->show();
            return false;
        }

        return true;
    }

    /**
     * Have the logout process do some Facebook Connect cookie cleanup
     *
     * @param Action $action the current action
     *
     * @return void
     */

    function onStartLogout($action)
    {
        $action->logout();
        $fbuid = $this->loggedIn();

        if (!empty($fbuid)) {
            try {
                $facebook = getFacebook();
                $facebook->expire_session();
            } catch (Exception $e) {
                common_log(LOG_WARNING, 'Facebook Connect Plugin - ' .
                           'Could\'t logout of Facebook: ' .
                           $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Get the URL of the user's Facebook avatar
     *
     * @param int $fbuid the Facebook user ID
     *
     * @return string $url the url for the user's Facebook avatar
     */

    function getProfilePicURL($fbuid)
    {
        $facebook = getFacebook();
        $url      = null;

        try {

            $fqry = 'SELECT pic_square FROM user WHERE uid = %s';

            $result = $facebook->api_client->fql_query(sprintf($fqry, $fbuid));

            if (!empty($result)) {
                $url = $result[0]['pic_square'];
            }

        } catch (Exception $e) {
            common_log(LOG_WARNING, 'Facebook Connect Plugin - ' .
                       "Facebook client failure requesting profile pic!");
        }

        return $url;
    }

    /**
     * Add a Facebook queue item for each notice
     *
     * @param Notice $notice      the notice
     * @param array  &$transports the list of transports (queues)
     *
     * @return boolean hook return
     */

    function onStartEnqueueNotice($notice, &$transports)
    {
        array_push($transports, 'facebook');
        return true;
    }

    /**
     * broadcast the message when not using queuehandler
     *
     * @param Notice &$notice the notice
     * @param array  $queue   destination queue
     *
     * @return boolean hook return
     */

    function onUnqueueHandleNotice(&$notice, $queue)
    {
        if (($queue == 'facebook') && ($this->_isLocal($notice))) {
            facebookBroadcastNotice($notice);
            return false;
        }
        return true;
    }

    /**
     * Determine whether the notice was locally created
     *
     * @param Notice $notice the notice
     *
     * @return boolean locality
     */

    function _isLocal($notice)
    {
        return ($notice->is_local == Notice::LOCAL_PUBLIC ||
                $notice->is_local == Notice::LOCAL_NONPUBLIC);
    }

    /**
     * Add Facebook queuehandler to the list of daemons to start
     *
     * @param array $daemons the list fo daemons to run
     *
     * @return boolean hook return
     *
     */

    function onGetValidDaemons($daemons)
    {
        array_push($daemons, INSTALLDIR .
                   '/plugins/Facebook/facebookqueuehandler.php');
        return true;
    }

}
