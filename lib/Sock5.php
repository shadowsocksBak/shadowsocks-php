<?php

/**
 * sock5头解析
 */
class Sock5
{

    const ADDRTYPE_IPV4 = 1;

    const ADDRTYPE_IPV6 = 4;

    const ADDRTYPE_HOST = 3;

    /**
     * 解析shadowsocks客户端发来的socket5头部数据
     * @param string $buffer
     */
    public static function parseHeader($buffer)
    {
        $addr_type = ord($buffer[0]);
        switch ($addr_type) {
            case self::ADDRTYPE_IPV4:
                $dest_addr = ord($buffer[1]) . '.' . ord($buffer[2]) . '.' . ord($buffer[3]) . '.' . ord($buffer[4]);
                $port_data = unpack('n', substr($buffer, 5, 2));
                $dest_port = $port_data[1];
                $header_length = 7;
                break;
            case self::ADDRTYPE_HOST:
                $addrlen = ord($buffer[1]);
                $dest_addr = substr($buffer, 2, $addrlen);
                $port_data = unpack('n', substr($buffer, 2 + $addrlen, 2));
                $dest_port = $port_data[1];
                $header_length = $addrlen + 4;
                break;
            case self::ADDRTYPE_IPV6:
                //echo "todo ipv6 not support yet\n";
                return false;
            default:
                //echo "unsupported addrtype $addr_type\n";
                return false;
        }
        return array('type' => $addr_type,'addr' => $dest_addr,'port' => $dest_port,'length' => $header_length);
    }
}
?>
