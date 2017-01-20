# shadowsocks-php
php+swoole 版本的 shadowsocks ota 版本实现
# centos　php 和 swoole 安装（环境搭建）
## php 安装
yum install -y openssl* openssl openssl-devel ncurses-devel
yum install -y libxml* libxml2-devel
yum install -y libcurl* curl-devel 
yum install -y libjpeg-devel
yum install -y libpng-devel
yum install -y freetype-devel
yum install -y libmcrypt libmcrypt-devel

wget -c <http://jp2.php.net/get/php-5.6.29.tar.gz>
tar zxvf php-5.6.29.tar.gz
cd php-5.6.29
./configure \
--prefix=/usr/local/php56 \
--with-config-file-path=/usr/local/php56/etc \
--enable-fpm \
--with-fpm-user=www \
--with-fpm-group=www \
--with-mysqli=mysqlnd \
--with-pdo-mysql=mysqlnd \
--with-iconv-dir \
--with-freetype-dir=/usr/local/freetype \
--with-jpeg-dir -\
-with-png-dir \
--with-zlib \
--with-libxml-dir=/usr \
--enable-xml \
--disable-rpath \
--enable-bcmath \
--enable-shmop \
--enable-sysvsem \
--enable-inline-optimization \
--with-curl \
--enable-mbregex \
--enable-mbstring \
--with-mcrypt \
--enable-ftp \
--with-gd \
--enable-gd-native-ttf \
--with-openssl \
--with-mhash \
--enable-pcntl \
--enable-sockets \
--with-xmlrpc \
--enable-zip \
--enable-soap \
--with-gettext \
--disable-fileinfo \
--enable-opcache


make && make install
cp php.ini-production /usr/local/php56/etc/php.ini
cd ..
php -v 如果不是你当前安装的php版本
建立软连接
ln -s /usr/local/php56/bin/php /usr/bin/php
ln -s /usr/local/php56/bin/phpize /usr/bin/phpize
ln -s /usr/local/php56/bin/pear /usr/bin/pear
ln -s /usr/local/php56/bin/peardev /usr/bin/peardev
ln -s /usr/local/php56/bin/pecl /usr/bin/pecl
ln -s /usr/local/php56/bin/php-cgi /usr/bin/php-cgi
ln -s /usr/local/php56/bin/php-config /usr/bin/php-config
ln -s /usr/local/php56/bin/phpdbg /usr/bin/phpdbg
## swoole 扩展安装
<http://wiki.swoole.com/wiki/page/6.html>
可直接通过 pecl安装 也可编译安装。
pecl安装：
pecl install swoole
编译安装：
wget <https://github.com/swoole/swoole-src/archive/v1.8.13-stable.zip>
unzip v1.8.13-stable.zip
cd swoole-src-1.8.13-stable
phpize
./configure -enable-swoole-debug &&sudo make && sudo make install
./configure && sudo make && sudo make install


编辑  /usr/local/php7/etc/php.ini 加入 extension=swoole.so
php --ri swoole 可查看swoole 扩展详细信息
也可通过 php -m 或 phpinfo() 来查看是否成功加载了swoole，
如果没有可能 是php.ini 的路径不对，
可以使用 php -i | grep php.ini 来定位到 php.ini 的绝对路径

## License
GPLv3 and latest
