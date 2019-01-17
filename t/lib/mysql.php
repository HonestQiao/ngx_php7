<?php
/**
 *  Copyright(c) 2017-2018 rryqszq4 rryqszq@gmail.com
 */

namespace ngx\php;

class mysql {

    const VERSION = "0.1.0";

    private $socket = null;

    private $packet_data = null;

    private $headerNum = 0;
    private $headerCurr = 0;
    private $resultState = 0;
    private $resultFields = array();

    public function __construct() {
        $this->socket = ngx_socket_create();
    }

    private function set_byte3($n) {
        return chr($n&0xff)
                .chr(($n>>8)&0xff)
                .chr(($n>>16)&0xff);
    }

    private function set_byte4($n){
        return chr($n&0xff)
                .chr(($n>>8)&0xff)
                .chr(($n>>16)&0xff)
                .chr(($n>>24)&0xff);
    }

    private function print_bin($result) {
        $hex_arr="";
        $bin_arr="";
        for ($i = 0,$j=1; $i < strlen($result);$i++,$j++) {
                $bytes[] = ord($result[$i]);
                if ($j % 8 == 0) {
                    $hex_arr .= bin2hex(chr($bytes[$i]))."   ";
                }else {
                    $hex_arr .= bin2hex(chr($bytes[$i]))." ";
                }
                if ( in_array($bytes[$i], array(0,10)) ) {
                    $bin_arr .= '.';
                }else {
                    $bin_arr .= chr($bytes[$i]);
                }

                if ($j == strlen($result)) {
                    echo "{$hex_arr}   {$bin_arr}\n";
                }

                if ($j % 16 == 0) {
                    echo "{$hex_arr}   {$bin_arr}\n";
                    $hex_arr="";
                    $bin_arr="";
                }
                //var_dump(bin2hex(chr($bytes[$i])).'   '.chr($bytes[$i]));
        }
        //var_dump($bytes);
    }

    private function length_encode_integer($data) {
        $start = 0;
        $first = ord(substr($data, 0, 1));
        if ($first <= 250) {
            return $first;
        }
        if ($first === 251) {
            return null;
        }
        if ($first === 252) {
            $start += 1;
            return unpack('v', substr($data, $start, 2));
        }
        if ($first === 253) {
            $start += 1;
            return unpack('V', substr($data, $start, 3)."\0");
        }
        $start += 1;
        return unpack('P', substr($data, $start, 8));
    }

    private function write_packet($data, $len, $chr=1) {
        $pack = $this->set_byte3($len).chr($chr).$data;
        var_dump("pack: ".$pack);

        //$pack = substr_replace(pack("V", $len), chr(1), 3, 1).$data;
        //var_dump(($pack == $pack1));

        $this->print_bin($pack);

        yield ngx_socket_send($this->socket, $pack, strlen($pack));
    }

    private function read_packet_1($idx=0) {
        yield ngx_socket_recv($this->socket, $result, 4);
        $this->print_bin($result);
        $len = unpack('v',substr($result, $idx, 3));
        var_dump($len);
        $len1 = $len = $len[1];
        var_dump($len1);
        $data = '';
        yield ngx_socket_recv($this->socket, $data, $len);
        $this->print_bin($data);
        $len = ord($data[0]);
        if ($len == 0x00) {
            var_dump("OK packet");
            //yield from $this->result_set_packet();
        }else if ($len == 0xff){
            var_dump("Error packet");
        }else if ($len == 0xfe) {
            var_dump("EOF packet");
        }else{
            var_dump("Data packet");
            if ($len1 == 1 && $this->resultState == 1) {
                var_dump("fields");
                yield from $this->result_set_packet();
                yield from $this->result_set_packet();
                yield from $this->result_set_packet();
                yield from $this->result_set_packet();
                $this->resultState = 2;
            }else if ($this->resultState == 2) {
                var_dump("rows");


            }
        }

        //$this->print_bin($result.$data);

        $this->packet_data = $data;
    }

    private function read_packet() {
        var_dump("read_packet_2");
        yield ngx_socket_recv($this->socket, $result, 4);
        $this->print_bin($result);
        $field_count = unpack('v', substr($result, 0, 3))[1];
        var_dump("field_count: ".$field_count);
        $data = '';
        yield ngx_socket_recv($this->socket, $data, $field_count);
        $this->print_bin($data);
        if ($field_count === 0x00) {
            var_dump("OK packet");
        }else if ($field_count === 0xff) {
            var_dump("Error packet");
        }else if ($field_count === 0xfe) {
            var_dump("EOF packet");
        }else {
            var_dump("Data packet");
            if ($field_count == 1 && $this->resultState == 0) {
                var_dump("header set");
                $this->headerNum = ord($data);
                var_dump($this->headerNum);
                $this->resultState = 1;
                yield from $this->read_packet();
            }else if ($this->resultState == 1) {
                var_dump('fields');
                if ($this->headerCurr < $this->headerNum - 1) {
                    $this->data_field_packet($data);
                    $this->headerCurr++;
                    yield from $this->read_packet();
                }else {
                    $this->data_field_packet($data);
                    $this->headerCurr++;
                    $this->resultState = 2;
                    yield from $this->read_packet();
                }
            }else if ($this->resultState == 2) {
                var_dump("rows");
            }else {
                return $data;
            }
        }


    }

    private function handshake_packet() {
        $data = ( yield from $this->read_packet() );

        var_dump("packet length: ".$len);

        $protocol_ver = ord(substr($data, 0, 1));

        var_dump("protocol version: ".$protocol_ver);

        var_dump(strpos($data, "\0", 2));

        var_dump("server version: ".substr($data, 1, strpos($data, "\0", 2)-1));

        $pos = strpos($data, "\0", 2)+1;

        $thread_id = unpack("V", substr($data, $pos, 4));
        $thread_id = $thread_id[1];
        var_dump("thread id: ".$thread_id);

        $pos += 4;
        var_dump($pos);

        $scramble = substr($data, $pos, 8);

        var_dump($scramble);

        #$pos = 1+$protocol_ver+4 + 9;

        $capabilities = unpack('v', substr($data, $pos+9, 2));
        $capabilities = $capabilities[1];
        var_dump("server capabilities: ".$capabilities);

        $server_lang = ord(substr($data, $pos+9+2,1));
        var_dump("server lang: ".$server_lang);

        $server_status = unpack('v', substr($data, $pos+9+2+1, 2));
        $server_status = $server_status[1];
        var_dump("server status: ".$server_status);

        $more_capabilites = unpack('v', substr($data, $pos+9+2+1+2,2));
        $more_capabilites = $more_capabilites[1];
        var_dump("more capabilities: ".$more_capabilites);

        $capabilities = $capabilities | ($more_capabilites << 16);
        var_dump("server capabilities: ".$capabilities);

        $scramble_2 = substr($data, $pos+9+2+1+2+2+1+10, 21-8-1);
        var_dump($scramble_2);

        $scramble = $scramble.$scramble_2;
        var_dump("scramble: ".$scramble);
        var_dump(strlen($scramble));

        #$data = substr($data, $pos);

        #$data = substr($data, 1, $protocol_ver+1);


        //$this->print_bin($result.$data);

        return $scramble;
    }

    private function auth_packet($scramble, $user, $password, $database) {
        #client 
        $client_flags = 0x3f7cf;
        //$database = "sakila";
        //$user = "root";
        //$password = "123456";
        $stage1 = sha1($password,1);
        $stage2 = sha1($stage1,1);
        $stage3 = sha1($scramble.$stage2,1);
        $n = strlen($stage1);
        /*$bytes = array();
        for ($i = 0; $i < $n; $i++) {
            $bytes[] = ord($stage3[$i]) ^ ord($stage1[$i]);
        }


        $token = '';
        foreach ($bytes as $ch) {
            $token .= chr($ch);
        }*/
        $token = $stage1 ^ $stage3;


        $req = $this->set_byte4($client_flags)
                .$this->set_byte4(1024*1024)
                .chr(0)
                .str_repeat("\0", 23)
                .$user."\0"
                .chr(strlen($token)).$token
                .$database."\0";
        var_dump($req);

        $pack_len = 4 + 4 + 1 + 23 + strlen($user) + 1 + strlen($token) + 1 + strlen($database) + 1;
        var_dump($pack_len);

        yield from $this->write_packet($req, $pack_len);

        yield from $this->read_packet();

    }

    private function result_set_packet() {
        yield ngx_socket_recv($this->socket, $result, 4);
        $this->print_bin($result);
        $len = unpack('v',substr($result, 0, 3));
        $len = $len[1];
        yield ngx_socket_recv($this->socket, $result, $len);
        $this->print_bin($result);
        var_dump("data field");
        $this->data_field_packet($result);

    }

    private function data_field_packet($result) {
        $start = 0;
        $field = array();
        $field['catalog'] = $catalog = $this->parse_data_field($result, $start);
        var_dump($catalog);
        $field['db'] = $db = $this->parse_data_field($result, $start);
        var_dump($db);
        $field['table'] = $table = $this->parse_data_field($result, $start);
        var_dump($table);
        $field['ori_table'] == $ori_table = $this->parse_data_field($result, $start);
        var_dump($ori_table);
        $field['column'] = $column = $this->parse_data_field($result, $start);
        var_dump($column);
        $field['ori_column'] = $ori_column = $this->parse_data_field($result, $start);
        var_dump($ori_column);

        $this->print_bin(substr($result, $start));

        $this->print_bin(substr($result, $start, 1));
        // 0xC0
        $start += 1;
        $this->print_bin(substr($result, $start, 2));
        $field['charset'] = $charset = unpack('v', substr($result, $start, 2))[1];
        var_dump($charset);
        $start += 2;
        $field['length'] = $length = unpack('V', substr($result, $start, 4))[1];
        var_dump($length);
        $start += 4;
        $field['type'] = $type = ord(substr($result, $start, 1));
        var_dump($type);
        $start += 1;
        $field['flag'] = $flag = unpack('v', substr($result, $start, 2))[1];
        var_dump($flag);
        $start += 2;
        $field['decimals'] = $decimals = unpack('v', substr($result, $start, 2))[1];
        var_dump($decimals);

        $this->resultFields[] = $field;

    }

    private function parse_data_field($result, &$start) {
        $len = ord(substr($result, $start, 1));
        var_dump($len);
        $field = substr($result, $start + 1, $len);
        $start = $start + 1 + $len;
        return $field;
    }

    private function data_row_packet() {

    }

    public function connect($host="127.0.0.1", $port=3306, $user="root", $password="123456") {
        yield ngx_socket_connect($this->socket, $host, $port);

        $scramble = ( yield from $this->handshake_packet() );

        yield from $this->auth_packet($scramble, $user, $password, $database);

        yield from $this->query("select * from sakila.city limit 4;");
    }

    public function query($sql) {
        $sql = "select * from sakila.city limit 4;";
        #$sql = "select sleep(1);";
        $req = chr(0x03).$sql;
        $pack_len = strlen($sql) + 1;
        
        yield from $this->write_packet($req, $pack_len, 0);

        // header set
        //yield from $this->read_packet();

        // field
        yield from $this->read_packet();
        //$data = $this->packet_data;
        //var_dump($data);

        //$this->print_bin($data);

        // EOF
        //yield from $this->read_packet();

        // rows
        //yield from $this->read_packet(0);
    }

    public function close() {
        yield ngx_socket_close($this->socket);
    }

}

?>