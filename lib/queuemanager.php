<?php
/**
 * StatusNet, the distributed open-source microblogging tool
 *
 * Abstract class for i/o managers
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
 * @category  QueueManager
 * @package   StatusNet
 * @author    Evan Prodromou <evan@status.net>
 * @author    Sarven Capadisli <csarven@status.net>
 * @author    Brion Vibber <brion@status.net>
 * @copyright 2009-2010 StatusNet, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://status.net/
 */

/**
 * Completed child classes must implement the enqueue() method.
 *
 * For background processing, classes should implement either socket-based
 * input (handleInput(), getSockets()) or idle-loop polling (idle()).
 */
abstract class QueueManager extends IoManager
{
    static $qm = null;

    public $master = null;
    public $handlers = array();
    public $groups = array();

    /**
     * Factory function to pull the appropriate QueueManager object
     * for this site's configuration. It can then be used to queue
     * events for later processing or to spawn a processing loop.
     *
     * Plugins can add to the built-in types by hooking StartNewQueueManager.
     *
     * @return QueueManager
     */
    public static function get()
    {
        if (empty(self::$qm)) {

            if (Event::handle('StartNewQueueManager', array(&self::$qm))) {

                $enabled = common_config('queue', 'enabled');
                $type = common_config('queue', 'subsystem');

                if (!$enabled) {
                    // does everything immediately
                    self::$qm = new UnQueueManager();
                } else {
                    switch ($type) {
                     case 'db':
                        self::$qm = new DBQueueManager();
                        break;
                     case 'stomp':
                        self::$qm = new StompQueueManager();
                        break;
                     default:
                        throw new ServerException("No queue manager class for type '$type'");
                    }
                }
            }
        }

        return self::$qm;
    }

    /**
     * @fixme wouldn't necessarily work with other class types.
     * Better to change the interface...?
     */
    public static function multiSite()
    {
        if (common_config('queue', 'subsystem') == 'stomp') {
            return IoManager::INSTANCE_PER_PROCESS;
        } else {
            return IoManager::SINGLE_ONLY;
        }
    }

    function __construct()
    {
        $this->initialize();
    }

    /**
     * Store an object (usually/always a Notice) into the given queue
     * for later processing. No guarantee is made on when it will be
     * processed; it could be immediately or at some unspecified point
     * in the future.
     *
     * Must be implemented by any queue manager.
     *
     * @param Notice $object
     * @param string $queue
     */
    abstract function enqueue($object, $queue);

    /**
     * Build a representation for an object for logging
     * @param mixed
     * @return string
     */
    function logrep($object) {
        if (is_object($object)) {
            $class = get_class($object);
            if (isset($object->id)) {
                return "$class $object->id";
            }
            return $class;
        }
        if (is_string($object)) {
            $len = strlen($object);
            $fragment = mb_substr($object, 0, 32);
            if (mb_strlen($object) > 32) {
                $fragment .= '...';
            }
            return "string '$fragment' ($len bytes)";
        }
        return strval($object);
    }

    /**
     * Encode an object for queued storage.
     * Next gen may use serialization.
     *
     * @param mixed $object
     * @return string
     */
    protected function encode($object)
    {
        if ($object instanceof Notice) {
            return $object->id;
        } else if (is_string($object)) {
            return $object;
        } else {
            throw new ServerException("Can't queue this type", 500);
        }
    }

    /**
     * Decode an object from queued storage.
     * Accepts back-compat notice reference entries and strings for now.
     *
     * @param string
     * @return mixed
     */
    protected function decode($frame)
    {
        if (is_numeric($frame)) {
            return Notice::staticGet(intval($frame));
        } else {
            return $frame;
        }
    }

    /**
     * Instantiate the appropriate QueueHandler class for the given queue.
     *
     * @param string $queue
     * @return mixed QueueHandler or null
     */
    function getHandler($queue)
    {
        if (isset($this->handlers[$queue])) {
            $class = $this->handlers[$queue];
            if (class_exists($class)) {
                return new $class();
            } else {
                common_log(LOG_ERR, "Nonexistent handler class '$class' for queue '$queue'");
            }
        } else {
            common_log(LOG_ERR, "Requested handler for unkown queue '$queue'");
        }
        return null;
    }

    /**
     * Get a list of registered queue transport names to be used
     * for this daemon.
     *
     * @return array of strings
     */
    function getQueues()
    {
        $group = $this->activeGroup();
        return array_keys($this->groups[$group]);
    }

    /**
     * Initialize the list of queue handlers
     *
     * @event StartInitializeQueueManager
     * @event EndInitializeQueueManager
     */
    function initialize()
    {
        // @fixme we'll want to be able to listen to particular queues...
        if (Event::handle('StartInitializeQueueManager', array($this))) {
            $this->connect('plugin', 'PluginQueueHandler');
            $this->connect('omb', 'OmbQueueHandler');
            $this->connect('ping', 'PingQueueHandler');
            if (common_config('sms', 'enabled')) {
                $this->connect('sms', 'SmsQueueHandler');
            }

            // XMPP output handlers...
            $this->connect('jabber', 'JabberQueueHandler');
            $this->connect('public', 'PublicQueueHandler');
            
            // @fixme this should get an actual queue
            //$this->connect('confirm', 'XmppConfirmHandler');

            // For compat with old plugins not registering their own handlers.
            $this->connect('plugin', 'PluginQueueHandler');

            $this->connect('xmppout', 'XmppOutQueueHandler', 'xmppdaemon');

        }
        Event::handle('EndInitializeQueueManager', array($this));
    }

    /**
     * Register a queue transport name and handler class for your plugin.
     * Only registered transports will be reliably picked up!
     *
     * @param string $transport
     * @param string $class
     * @param string $group
     */
    public function connect($transport, $class, $group='queuedaemon')
    {
        $this->handlers[$transport] = $class;
        $this->groups[$group][$transport] = $class;
    }

    /**
     * @return string queue group to use for this request
     */
    function activeGroup()
    {
        $group = 'queuedaemon';
        if ($this->master) {
            // hack hack
            if ($this->master instanceof XmppMaster) {
                return 'xmppdaemon';
            }
        }
        return $group;
    }

    /**
     * Send a statistic ping to the queue monitoring system,
     * optionally with a per-queue id.
     *
     * @param string $key
     * @param string $queue
     */
    function stats($key, $queue=false)
    {
        $owners = array();
        if ($queue) {
            $owners[] = "queue:$queue";
            $owners[] = "site:" . common_config('site', 'server');
        }
        if (isset($this->master)) {
            $this->master->stats($key, $owners);
        } else {
            $monitor = new QueueMonitor();
            $monitor->stats($key, $owners);
        }
    }
}
