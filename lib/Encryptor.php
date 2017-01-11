<?php

/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 加密解密类
 * @author walkor<walkor@workerman.net>
 */
class Encryptor
{

	protected $password;
	
    public $_key;

    protected $_method;

    protected $_cipher;

    protected $_decipher;

    protected $_bytesToKeyResults = array();

    protected static $_cachedTables = array();

    protected static $_encryptTable = array();

    protected static $_decryptTable = array();

    protected $_cipherIv;//我方发出去的数据的加密向量
	
	public $_decipherIv;//对方发来的数据的加密向量

    protected $_ivSent;

    protected static $_methodSupported = array(
        'aes-128-cfb' => array(16,16),
        'aes-192-cfb' => array(24,16),
        'aes-256-cfb' => array(32,16),
        'bf-cfb' => array(16,8),
        'camellia-128-cfb' => array(16,16),
        'camellia-192-cfb' => array(24,16),
        'camellia-256-cfb' => array(32,16),
        'cast5-cfb' => array(16,8),
        'des-cfb' => array(8,8),
        'idea-cfb' => array(16,8),
        'rc2-cfb' => array(16,8),
        'rc4' => array(16,0),
        'rc4-md5' => array(16,16),
        'seed-cfb' => array(16,16));

    public static function initTable($key)
    {
        $_ref = self::getTable($key);
        self::$_encryptTable = $_ref[0];
        self::$_decryptTable = $_ref[1];
    }

    public function __construct($key, $method)
    {
        $this->password = $key;
        $this->_method = $method;
        if ($this->_method == 'table') {
            $this->_method = null;
        }
        if ($this->_method) {
            $iv_size = openssl_cipher_iv_length($this->_method);
            $iv = openssl_random_pseudo_bytes($iv_size);
            $this->_cipher = $this->getcipher($this->password, $this->_method, 1, $iv);
        } else {
            if (! self::$_encryptTable) {
                $_ref = self::getTable($this->password);
                self::$_encryptTable = $_ref[0];
                self::$_decryptTable = $_ref[1];
            }
        }
    }

    protected static function getTable($key)
    {
        if (isset(self::$_cachedTables[$key])) {
            return self::$_cachedTables[key];
        }
        $int32Max = pow(2, 32);
        $table = array();
        $decrypt_table = array();
        $hash = md5($key, true);
        $tmp = unpack('V2', $hash);
        $al = $tmp[1];
        $ah = $tmp[2];
        $i = 0;
        while ($i < 256) {
            $table[$i] = $i;
            $i ++;
        }
        $i = 1;
        while ($i < 1024) {
            $table = merge_sort($table, 
                function ($x, $y) use($ah, $al, $i, $int32Max)
                {
                    return (($ah % ($x + $i)) * $int32Max + $al) % ($x + $i) - (($ah % ($y + $i)) * $int32Max + $al) % ($y + $i);
                });
            $i ++;
        }
        $table = array_values($table);
        $i = 0;
        while ($i < 256) {
            $decrypt_table[$table[$i]] = $i;
            ++ $i;
        }
        ksort($decrypt_table);
        $decrypt_table = array_values($decrypt_table);
        $result = array($table,$decrypt_table);
        self::$_cachedTables[$key] = $result;
        return $result;
    }

    public static function substitute($table, $buf)
    {
        $i = 0;
        $len = strlen($buf);
        while ($i < $len) {
            $buf[$i] = chr($table[ord($buf[$i])]);
            $i ++;
        }
        return $buf;
    }

    protected function getCipher($password, $method, $op, $iv)
    {
        $method = strtolower($method);
        $m = $this->getCipherLen($method);
        if ($m) {
            $ref = $this->EVPBytesToKey($password, $m[0], $m[1]);
            $key = $ref[0];
            $iv_ = $ref[1];
			$this->_key = $key;
            if ($iv == null) {
                $iv = $iv_;
            }
			$iv = substr($iv, 0, $m[1]);
            if ($op === 1) {
                $this->_cipherIv = $iv;
            }else{
				$this->_decipherIv = $iv;
			}
            if ($method === 'rc4-md5') {
                return $this->createRc4Md5Cipher($key, $iv, $op);
            } else {
                if ($op === 1) {
                    return new Encipher($method, $key, $iv);
                } else {
                    return new Decipher($method, $key, $iv);
                }
            }
        }
    }

    public function encrypt($buffer)
    {
        if ($this->_method) {
            $result = $this->_cipher->update($buffer);
            if ($this->_ivSent) {
                return $result;
            } else {
                $this->_ivSent = true;
                return $this->_cipherIv . $result;
            }
        } else {
            return self::substitute(self::$_encryptTable, $buffer);
        }
    }

    public function decrypt($buffer)
    {
        if ($this->_method) {
            if (! $this->_decipher) {
                $decipher_iv_len = $this->getCipherLen($this->_method);
                $decipher_iv_len = $decipher_iv_len[1];
                $decipher_iv = substr($buffer, 0, $decipher_iv_len);
                $this->_decipher = $this->getCipher($this->password, $this->_method, 0, $decipher_iv);
                $result = $this->_decipher->update(substr($buffer, $decipher_iv_len));
                return $result;
            } else {
                $result = $this->_decipher->update($buffer);
                return $result;
            }
        } else {
            return self::substitute(self::$_decryptTable, $buffer);
        }
    }

    protected function createRc4Md5Cipher($key, $iv, $op)
    {
        $rc4_key = md5($key . $iv);
        if ($op === 1) {
            return new Encipher('rc4', $rc4_key, '');
        } else {
            return new Decipher('rc4', $rc4_key, '');
        }
    }

    protected function EVPBytesToKey($password, $key_len, $iv_len)
    {
        $cache_key = "$password:$key_len:$iv_len";
        if (isset($this->_bytesToKeyResults[$cache_key])) {
            return $this->_bytesToKeyResults[$cache_key];
        }
        $m = array();
        $i = 0;
        $count = 0;
        while ($count < $key_len + $iv_len) {
            $data = $password;
            if ($i > 0) {
                $data = $m[$i - 1] . $password;
            }
            $d = md5($data, true);
            $m[] = $d;
            $count += strlen($d);
            $i += 1;
        }
        $ms = '';
        foreach ($m as $buf) {
            $ms .= $buf;
        }
        $key = substr($ms, 0, $key_len);
        $iv = substr($ms, $key_len, $key_len + $iv_len);
        $this->bytesToKeyResults[$password] = array($key,$iv);
        return array($key,$iv);
    }

    protected function getCipherLen($method)
    {
        $method = strtolower($method);
        return isset(self::$_methodSupported[$method]) ? self::$_methodSupported[$method] : null;
    }
}

class Encipher
{

    protected $_algorithm;

    protected $_key;

    protected $_iv;

    protected $_tail;

    protected $_ivLength;

    public function __construct($algorithm, $key, $iv)
    {
        $this->_algorithm = $algorithm;
        $this->_key = $key;
        $this->_iv = $iv;
        $this->_ivLength = openssl_cipher_iv_length($algorithm);
    }

    public function update($data)
    {
        if (strlen($data) == 0)
            return '';
        $tl = strlen($this->_tail);
        if ($tl)
            $data = $this->_tail . $data;
        $b = openssl_encrypt($data, $this->_algorithm, $this->_key, OPENSSL_RAW_DATA, $this->_iv);
        $result = substr($b, $tl);
        $dataLength = strlen($data);
        $mod = $dataLength % $this->_ivLength;
        if ($dataLength >= $this->_ivLength) {
            $iPos = - ($mod + $this->_ivLength);
            $this->_iv = substr($b, $iPos, $this->_ivLength);
        }
        $this->_tail = $mod != 0 ? substr($data, - $mod) : '';
        return $result;
    }
}

class Decipher extends Encipher
{

    public function update($data)
    {
        if (strlen($data) == 0)
            return '';
        $tl = strlen($this->_tail);
        if ($tl)
            $data = $this->_tail . $data;
        $b = openssl_decrypt($data, $this->_algorithm, $this->_key, OPENSSL_RAW_DATA, $this->_iv);
        $result = substr($b, $tl);
        $dataLength = strlen($data);
        $mod = $dataLength % $this->_ivLength;
        if ($dataLength >= $this->_ivLength) {
            $iPos = - ($mod + $this->_ivLength);
            $this->_iv = substr($data, $iPos, $this->_ivLength);
        }
        $this->_tail = $mod != 0 ? substr($data, - $mod) : '';
        return $result;
    }
}

function merge_sort($array, $comparison)
{
    if (count($array) < 2) {
        return $array;
    }
    $middle = ceil(count($array) / 2);
    return merge(merge_sort(slice($array, 0, $middle), $comparison), merge_sort(slice($array, $middle), $comparison), $comparison);
}

function slice($table, $start, $end = null)
{
    $table = array_values($table);
    if ($end) {
        return array_slice($table, $start, $end);
    } else {
        return array_slice($table, $start);
    }
}

function merge($left, $right, $comparison)
{
    $result = array();
    while ((count($left) > 0) && (count($right) > 0)) {
        if (call_user_func($comparison, $left[0], $right[0]) <= 0) {
            $result[] = array_shift($left);
        } else {
            $result[] = array_shift($right);
        }
    }
    while (count($left) > 0) {
        $result[] = array_shift($left);
    }
    while (count($right) > 0) {
        $result[] = array_shift($right);
    }
    return $result;
}

