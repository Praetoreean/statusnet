<?php
/**
 * StatusNet - the distributed open-source microblogging tool
 * Copyright (C) 2010, StatusNet, Inc.
 *
 * A sample module to show best practices for StatusNet plugins
 *
 * PHP version 5
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
 *
 * @package   StatusNet
 * @author    James Walker <james@status.net>
 * @copyright 2010 StatusNet, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html AGPL 3.0
 * @link      http://status.net/
 */

require_once 'Crypt/RSA.php';

class Magicsig extends Memcached_DataObject
{

    const PUBLICKEYREL = 'magic-public-key';
    
    public $__table = 'magicsig';

    public $user_id;
    public $keypair;
    public $alg;
    
    private $_rsa;

    public function __construct($alg = 'RSA-SHA256')
    {
        $this->alg = $alg;
    }
    
    public /*static*/ function staticGet($k, $v=null)
    {
        return parent::staticGet(__CLASS__, $k, $v);
    }


    function table()
    {
        return array(
            'user_id' => DB_DATAOBJECT_INT,
            'keypair' => DB_DATAOBJECT_STR + DB_DATAOBJECT_NOTNULL,
            'alg'     => DB_DATAOBJECT_STR
        );
    }

    static function schemaDef()
    {
        return array(new ColumnDef('user_id', 'integer',
                                   null, true, 'PRI'),
                     new ColumnDef('keypair', 'varchar',
                                   255, false),
                     new ColumnDef('alg', 'varchar',
                                   64, false));
    }


    function keys()
    {
        return array_keys($this->keyTypes());
    }

    function keyTypes()
    {
        return array('user_id' => 'K');
    }

    function insert()
    {
        $this->keypair = $this->toString();

        return parent::insert();
    }

    public function generate($key_length = 512)
    {
        PEAR::pushErrorHandling(PEAR_ERROR_RETURN);

        $keypair = new Crypt_RSA_KeyPair($key_length);
        $params['public_key'] = $keypair->getPublicKey();
        $params['private_key'] = $keypair->getPrivateKey();

        $this->_rsa = new Crypt_RSA($params);
        PEAR::popErrorHandling();

        $this->insert();
    }


    public function toString($full_pair = true)
    {
        $public_key = $this->_rsa->_public_key;
        $private_key = $this->_rsa->_private_key;

        $mod = base64_url_encode($public_key->getModulus());
        $exp = base64_url_encode($public_key->getExponent());
        $private_exp = '';
        if ($full_pair && $private_key->getExponent()) {
            $private_exp = '.' . base64_url_encode($private_key->getExponent());
        }

        return 'RSA.' . $mod . '.' . $exp . $private_exp; 
    }
    
    public static function fromString($text)
    {
        PEAR::pushErrorHandling(PEAR_ERROR_RETURN);

        $magic_sig = new Magicsig();
        
        // remove whitespace
        $text = preg_replace('/\s+/', '', $text);

        // parse components
        if (!preg_match('/RSA\.([^\.]+)\.([^\.]+)(.([^\.]+))?/', $text, $matches)) {
            return false;
        }
        
        $mod = base64_url_decode($matches[1]);
        $exp = base64_url_decode($matches[2]);
        if ($matches[4]) {
            $private_exp = base64_url_decode($matches[4]);
        }

        $params['public_key'] = new Crypt_RSA_KEY($mod, $exp, 'public');
        if ($params['public_key']->isError()) {
            $error = $params['public_key']->getLastError();
            common_log(LOG_DEBUG, 'RSA Error: '. $error->getMessage());
            return false;
        }
        if ($private_exp) {
            $params['private_key'] = new Crypt_RSA_KEY($mod, $private_exp, 'private');
            if ($params['private_key']->isError()) {
                $error = $params['private_key']->getLastError();
                common_log(LOG_DEBUG, 'RSA Error: '. $error->getMessage());
                return false;
            }
        }

        $magic_sig->_rsa = new Crypt_RSA($params);
        PEAR::popErrorHandling();

        return $magic_sig;
    }

    public function getName()
    {
        return $this->alg;
    }

    public function getHash()
    {
        switch ($this->alg) {

        case 'RSA-SHA256':
            return 'sha256';
        }

    }
    
    public function sign($bytes)
    {
        $sig = $this->_rsa->createSign($bytes, null, 'sha256');
        if ($this->_rsa->isError()) {
            $error = $this->_rsa->getLastError();
            common_log(LOG_DEBUG, 'RSA Error: '. $error->getMessage());
            return false;
        }

        return $sig;
    }

    public function verify($signed_bytes, $signature)
    {
        $result =  $this->_rsa->validateSign($signed_bytes, $signature, null, 'sha256');
        if ($this->_rsa->isError()) {
            $error = $this->keypair->getLastError();
            common_log(LOG_DEBUG, 'RSA Error: '. $error->getMessage());
            return false;
        }
        return $result;
    }
        
}

// Define a sha256 function for hashing
// (Crypt_RSA should really be updated to use hash() )
function sha256($bytes)
{
    return hash('sha256', $bytes);
}

function base64_url_encode($input)
{
    return strtr(base64_encode($input), '+/', '-_');
}

function base64_url_decode($input)
{
    return base64_decode(strtr($input, '-_', '+/'));
}