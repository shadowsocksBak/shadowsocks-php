# shadowsocks-php
php+swoole 版本的 shadowsocks ota 版本实现
## centos　php 和 swoole 安装（环境搭建）

<http://wiki.swoole.com/wiki/page/6.html>

### php 安装
php -v 查看php版本,如果是php-5.3.10 或更高版本 则可略过此步骤，直接安装swoole 扩展 </br>

yum install -y openssl* openssl openssl-devel ncurses-devel</br>
yum install -y libxml* libxml2-devel</br>
yum install -y libcurl* curl-devel</br>
yum install -y libjpeg-devel</br>
yum install -y libpng-devel</br>
yum install -y freetype-devel</br>
yum install -y libmcrypt libmcrypt-devel</br></br>

wget -c <http://jp2.php.net/get/php-5.6.29.tar.gz></br>
tar zxvf php-5.6.29.tar.gz</br>
cd php-5.6.29</br>
./configure \ </br>
--prefix=/usr/local/php56 \ </br>
--with-config-file-path=/usr/local/php56/etc \ </br>
--enable-fpm \ </br>
--with-fpm-user=www \ </br>
--with-fpm-group=www \ </br>
--with-mysqli=mysqlnd \ </br>
--with-pdo-mysql=mysqlnd \ </br>
--with-iconv-dir \ </br>
--with-freetype-dir=/usr/local/freetype \ </br>
--with-jpeg-dir \ </br>
--with-png-dir \ </br>
--with-zlib \ </br>
--with-libxml-dir=/usr \ </br>
--enable-xml \ </br>
--disable-rpath \ </br>
--enable-bcmath \ </br>
--enable-shmop \ </br>
--enable-sysvsem \ </br>
--enable-inline-optimization \ </br>
--with-curl \ </br>
--enable-mbregex \ </br>
--enable-mbstring \ </br>
--with-mcrypt \ </br>
--enable-ftp \ </br>
--with-gd \ </br>
--enable-gd-native-ttf \ </br>
--with-openssl \ </br>
--with-mhash \ </br>
--enable-pcntl \ </br>
--enable-sockets \ </br>
--with-xmlrpc \ </br>
--enable-zip \ </br>
--enable-soap \ </br>
--with-gettext \ </br>
--disable-fileinfo \ </br>
--enable-opcache</br>


make && make install</br>
cp php.ini-production /usr/local/php56/etc/php.ini</br>
cd ..</br>
php -v 如果不是你当前安装的php版本</br>
建立软连接</br>
ln -s /usr/local/php56/bin/php /usr/bin/php</br>
ln -s /usr/local/php56/bin/phpize /usr/bin/phpize</br>
ln -s /usr/local/php56/bin/pear /usr/bin/pear</br>
ln -s /usr/local/php56/bin/peardev /usr/bin/peardev</br>
ln -s /usr/local/php56/bin/pecl /usr/bin/pecl</br>
ln -s /usr/local/php56/bin/php-cgi /usr/bin/php-cgi</br>
ln -s /usr/local/php56/bin/php-config /usr/bin/php-config</br>
ln -s /usr/local/php56/bin/phpdbg /usr/bin/phpdbg</br>
### swoole 扩展安装</br>
<http://wiki.swoole.com/wiki/page/6.html></br>
可直接通过 pecl安装 也可编译安装。</br>
pecl安装：</br>
pecl install swoole</br>
编译安装：</br>
wget <https://github.com/swoole/swoole-src/archive/v1.8.13-stable.zip></br>
unzip v1.8.13-stable.zip</br>
cd swoole-src-1.8.13-stable</br>
phpize</br>
./configure -enable-swoole-debug &&sudo make && sudo make install</br>
./configure && sudo make && sudo make install</br></br>


编辑  /usr/local/php56/etc/php.ini 加入 extension=swoole.so</br>
php --ri swoole 可查看swoole 扩展详细信息</br>
也可通过 php -m 或 phpinfo() 来查看是否成功加载了swoole，</br>
如果没有可能 是php.ini 的路径不对，</br>
可以使用 php -i | grep php.ini 来定位到 php.ini 的绝对路径</br>
## 其他
加密类使用了 [workerman](https://github.com/walkor/shadowsocks-php) 版本的实现</br>
server 参照 <https://github.com/Iceux/shadowsocks-php></br>
自己实现了 OTA 部分 和参照 python 的ss 实现了udp部分，以及其他优化。</br>
在服务器上稳定运行，一切正常，内存消耗低。
## License
GPLv3 and latest
