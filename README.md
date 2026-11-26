# About this fork
This repository is a community-maintained fork of
[RackTables](https://github.com/RackTables/racktables). It is not the official
repository and is not affiliated with or endorsed by the original authors. It
was created because upstream is no longer actively maintained, and its purpose
is to keep RackTables working on current PHP and MySQL/MariaDB releases and to
fix reported bugs and security issues. Its releases are numbered
`0.23.0-community` and later; version numbers 0.22.x are left to upstream.

**Upgrading is one-way.** A database upgraded by this fork cannot be used with
upstream RackTables code again, so back up the database first. Read
[Upgrading to 0.23.0-community](#upgrading-to-0230-community) before you
upgrade.

**0.24.0-community converts stored text** and changes what plugins and
`local.php` code must do when they print it. Read
[Upgrading to 0.24.0-community](#upgrading-to-0240-community) if you have
code of your own or read the database directly.

## How it came to be, and a word about AI
I have been a happy RackTables user for years. Over those years I also watched
the bugs and issues pile up in the original tracker, while the author
understandably has no time left to work through them. Then came a night when I
could not sleep, with an AI subscription whose unused quota was about to
expire, and so I started fixing things, one issue after another.

So, to be honest with you: most of the changes in this fork were written with
the help of AI coding assistants, under my direction. To keep that from being
a reason for worry rather than a convenience, every fix follows the same
routine: the problem is reproduced first, the fix comes with a regression test
where the suite has a place for one, the change gets a second, independent
automated review, and the whole test suite has to pass on PHP 7.4 and 8.6 with
MariaDB (the upgrade path on MySQL as well) before anything is merged. It is
still software, and it can still be wrong. If something breaks for you,
please tell me.

Please report bugs in this fork via its
[GitHub issues](https://github.com/michaelkalus/racktables/issues). Reports
about the original project are kept at https://bugs.racktables.org.

## Changes compared to upstream 0.22.0
The complete list is in the `0.24.1-community`, `0.24.0-community`, `0.23.1-community` and
`0.23.0-community` sections of `ChangeLog`, together with the `0.22.1` section
below them: the changes
upstream made after 0.22.0 but never released, which this fork includes. In
short:

**Current platforms**
* runs on current PHP 8 releases without errors and without deprecation messages
  (no `strptime()`/`strftime()`, no redeclared `array_first()`/`array_last()`);
* installs on MySQL 8.4, and a database of any release since 0.20.0 upgrades
  on current MariaDB and on MySQL 8.0 and 8.4;
* the two legacy non-unique keys that MySQL 8.4 refuses are made unique, so
  an upgraded database can be dumped and restored there.

**Security**
* stored and submitted text is escaped on the search results (CVE-2023-49453),
  entity, IP address, SLB and NAT pages, in operation messages and in the
  802.1Q popups; wiki links are restricted to safe schemes;
* port link, object link, file link and RS pool operations are restricted to
  the entity the user was authorised for;
* passwords of local accounts are stored with `password_hash()` instead of
  unsalted SHA-1, and existing hashes are replaced on login; the LDAP cache
  does the same for directory passwords (since 0.23.1-community);
* user names are escaped in LDAP search filters, and sshnokey credentials are
  no longer sent as session input;
* while the directory server is down, a failed login with a wrong password
  no longer removes the LDAP cache entry of the user, which used to lock the
  user out until the server came back (since 0.23.1-community).

**Stored text**
* every form stores text as it was typed, and the upgrade decodes the values
  that older forms stored HTML-escaped (since 0.24.0-community); names with
  `&` or `<` can be searched, sorted and edited without turning into entities;
* names, labels, comments and configuration values are escaped where they are
  printed, in about a hundred more places than before;
* user names are used as typed by the login and stored as typed (since
  0.24.1-community), so a login name with `'` or `&` reaches the LDAP server
  unchanged.

**Data integrity**
* rack, row, location and object changes, bulk object adds and the SNMP port
  list sync are atomic, and a failed operation rolls back completely;
* deleting an entity releases its file links and tags; a location cannot
  become its own ancestor;
* a rack without a row no longer breaks the Objects list, its own page or the
  pages of its objects, and can be assigned a row again;
* common database errors are reported in plain words.

**Long-standing bugs**
* search: addresses of /32 and /128 networks, strings with three dots that
  are not IPv4 addresses, the matched value of object fields;
* IP: stricter parsing of addresses, no Subnet-Router anycast reservation on
  IPv6 /127 and /128, Live PTR import keeps away from reserved addresses, a
  network that contains another network can be deleted;
* accounts: a blank password field keeps the password, autotags accept
  `user@REALM` and `DOMAIN\user`, LDAP group membership and cache fixes;
* devices: SNMP sync looks up sysObjectID in numeric form, Cisco 2851 is
  supported, ssh banners and FTOS 8 messages are no longer taken for errors,
  802.1Q configuration follows a renamed port.

**Tests**
* the test suite no longer depends on test order or on MySQL, and the upgrade
  from every release since 0.20.0 is tested automatically.

Some of these changes were adopted from unmerged upstream pull requests and
keep the authorship of their original authors. Many others fix reports from
the original bug tracker; the `ChangeLog` names them as Mantis#NNNN. The licence is unchanged:
GPLv2, see `COPYING` and `LICENSE`.

# Welcome!
Thank you for selecting RackTables as your datacenter management solution!
If you are looking for documentation or wish to send feedback, please
look for the respective links at [project's web-site](https://racktables.org).

# How to install RackTables

## 1. Prepare the server

RackTables uses a web-server with PHP (7.0 is the minimum required version, 7.1
is the minimum tested version, 7.3 is the recommended version) for front-end and
a MySQL/MariaDB server version 5 or later for back-end. The most commonly used
web-server for RackTables is Apache httpd.

### 1.1. Install MySQL/MariaDB server

| Distribution       | How to do                                                               |
| ------------------ | ----------------------------------------------------------------------- |
| Debian 11-12       | `apt-get install mariadb-server`                                        |
| FreeBSD 10         | `pkg install mysql56-server`                                            |
| Ubuntu 20.04       | `apt-get install mariadb-server`                                        |
| Ubuntu 22.04       | `apt-get install mariadb-server`                                        |
| Ubuntu 24.04       | `apt-get install mariadb-server`                                        |
| RHEL 7             | `yum install -y mariadb-server mariadb`                                 |

### 1.2. Enable Unicode in the MySQL/MariaDB server

| Distribution       | How to do                                                                                                          |
| ------------------ | ------------------------------------------------------------------------------------------------------------------ |
| Debian 11-12       | No action required, comes configured for UTF-8 by default.                                                         |
| Ubuntu 20.04       | No action required, comes configured for UTF-8 by default.                                                         |
| RHEL 7             | add `character-set-server=utf8` line to `[server]` section of `/etc/my.cnf.d/server.cnf` file and restart mysqld   |

### 1.3. Install PHP and Apache httpd (or nginx)

| Distribution       | How to do                                                                            |
| ------------------ | ------------------------------------------------------------------------------------ |
| Debian 11-12       | `apt-get install apache2-bin libapache2-mod-php php-gd php-mysql php-mbstring php-bcmath php-json php-snmp && systemctl restart apache2`
| FreeBSD 10         | see note 1.3.c                                                                       | 
| Ubuntu any release | `apt-get install apache2-bin libapache2-mod-php php-gd php-mysql php-mbstring php-bcmath php-json php-snmp`
| RHEL 7             | `subscription-manager repos --enable=rhel-server-rhscl-7-rpms`
|                    | `yum install httpd24 rh-php70 rh-php70-php-mysqlnd rh-php70-php-pdo rh-php70-php-gd rh-php70-php-snmp rh-php70-php-mbstring rh-php70-php-bcmath rh-php70-php-ldap rh-php70-php`

#### 1.3.a. [no longer applies]

#### 1.3.b. [no longer applies]

#### 1.3.c. FreeBSD 10
There are 3 different ways how you can install RackTables and its dependencies on FreeBSD.

###### A. use pkg (Binary Package Management) (not always the newest version)
```
# pkg install racktables
# pkg install mod_php56 mysql56-server
```
As of March 2017 this will install RackTables Version 0.20.11 and its dependencies (php 5.6, mysql-server 5.6 and apache 2.4).

###### B. use the ports system (possibly more recent than pkg)
```
# cd /usr/ports/sysutils/racktables
# make install
# pkg install mod_php56 mysql56-server
```
As of March 2017 this will install RackTables Version 0.20.11 and build and install its dependencies (php 5.6, mysql-server 5.6 and apache 2.4).

###### C. manual (newest version)
Install dependencies with pkg:
```
# pkg install php70-bcmath php70-curl php70-filter php70-gd php70-gmp php70-json php70-mbstring php70-openssl php70-pdo php70-pdo_mysql php70-session php70-simplexml php70-snmp php70-sockets
# pkg install mod_php70 mysql56-server
```

unpack tar.gz/zip archive to `/usr/local/www`

symlink racktables dir
```
# cd /usr/local/www
# ln -s RackTables-0.20.xx racktables
```

##### Common install steps
Apache users should create a racktables.conf file under their apache
Includes directory with the following contents:
```
AddType  application/x-httpd-php         .php

<Directory /usr/local/www/racktables/wwwroot>
	DirectoryIndex index.php
	Require all granted
</Directory>
Alias /racktables /usr/local/www/racktables/wwwroot
```

Start services:
```
# echo 'apache24_enable="YES"' >> /etc/rc.conf
# service apache24 start
# echo 'mysql_enable="YES"' >> /etc/rc.conf
# service mysql-server start
```

Browse to http://address.to.your.server/racktables/index.php and follow the instructions.

Note: set `secret.php` permissions when prompted.
```
# chown www:www /usr/local/www/racktables/wwwroot/inc/secret.php
# chmod 400 /usr/local/www/racktables/wwwroot/inc/secret.php
```

#### 1.3.d. RHEL 7
Apache configuration and webroot is under /opt/rh/httpd24/root/

## 2. Copy the files
Unpack the tar.gz/zip archive to a directory of your choice and configure Apache
httpd to use `wwwroot` subdirectory as a new DocumentRoot. Alternatively,
symlinks to `wwwroot` or even to `index.php` from an existing DocumentRoot are
also possible and often advisable (see `README.Fedora`).

## 3. Run the installer
Open the configured RackTables URL and you will be prompted to configure
and initialize the application.

| Distribution    | Apache httpd UID:GID    | MySQL/MariaDB UNIX socket path   |
| --------------- | ----------------------- | -------------------------------- |
| Debian 11-12    | `www-data:www-data`     | `/run/mysqld/mysqld.sock`        |
| Ubuntu 20.04    | `www-data:www-data`     | `/var/run/mysqld/mysqld.sock`    |
| Ubuntu 22.04    | `www-data:www-data`     | `/run/mysqld/mysqld.sock`        |
| Ubuntu 24.04    | `www-data:www-data`     | `/run/mysqld/mysqld.sock`        |

# How to upgrade RackTables

0. **Backup your database** and check the release notes below before actually
   starting the upgrade.
1. Remove all existing files except configuration (the `inc/secret.php` file)
   and local plugins (in the `plugins/` directory).
2. Put the contents of the new tar.gz/zip archive into the place.
3. Open the RackTables page in a browser. The software will detect version
   mismatch and display a message telling to log in as admin to finish
   the upgrade.
4. Do that and report any errors to the
   [issue tracker of this fork](https://github.com/michaelkalus/racktables/issues).

## Release notes

### Upgrading to 0.24.1-community
User names are now used and stored as they are typed. Until this release the
login name of HTTP basic, LDAP and httpd authentication had the characters
`& < > " '` replaced by HTML entities before it was looked up, sent to the
LDAP server and recorded with each change, and the "add user" form stored
account names the same way. The upgrade decodes the names of the accounts, of
the user preferences and of the change records once, and the generated
messages of the port and IP address logs (as a whole, including text typed to
look like an entity). Names without these characters, which is nearly all of
them, are not affected. An account whose decoded name belongs to another
account keeps its name, preferences and records as they are. With SAML
authentication, which always used the name as it is, only the account names
are decoded. The LDAP cache is emptied, every LDAP user is verified against
the server on the next request.

The 0.24.0-community upgrade set the time of each object history record it
decoded to the time of the upgrade. This is fixed for databases upgraded from
now on; a database that went through 0.24.0-community already keeps the wrong
time in those records (the records themselves are intact).

`stringForLabel()`, `stringForTD()` and `stringForOption()` escape every
ampersand again. Text that was typed to look like an entity (the five
characters `&amp;`) is displayed as typed. **Code of your own** that passes
already escaped text to these functions now shows the entity; pass the text as
it is.

Take a backup of the database before the upgrade: the conversion cannot be
undone.

### Upgrading to 0.24.0-community
Text is now stored as it was typed. Until this release most forms stored
names, labels, asset tags, comments, descriptions and configuration values
with the characters `& < > " '` replaced by HTML entities (for example
`R&amp;D`), while other forms and the data read from devices stored them as
they are. The upgrade decodes the existing values once. Pages look the same
as before; the edit forms, the search, sorting and anything that reads the
database directly now see the real characters.

**If you have plugins, a `local.php` or reports of your own:** text read from
the database must be escaped where it is printed, for example with
`stringForLabel()` or `htmlspecialchars()`. Code that printed such values as
they are was safe only because the values were stored escaped, and is not any
more. Programs that read the database directly now find `R&D` where `R&amp;D`
was.

Text that was typed to look like an entity on purpose (the five characters
`&amp;`) is decoded as well and becomes the character itself. A value is left
as it is where the decoded form would be a duplicate of another asset tag, or
of another port name of the same object and type; edit such a value by hand.
User names and the log records are not converted.

Take a backup of the database before the upgrade: the conversion cannot be
undone.

### Upgrading to 0.23.1-community
With LDAP authentication and its cache enabled, the cache used to keep an
unsalted SHA-1 digest of the directory password of every cached user. It now
keeps a password_hash() result. The upgrade empties the cache, so the first
request of each user after the upgrade asks the directory server; no action
is needed.
A client that does not keep the session cookie, such as a script that sends
HTTP basic authentication with each request, now spends the time of one
password verification (a fraction of a second) on every request.

### Upgrading to 0.23.0-community
This is the first release of the community fork. A database of RackTables
0.22.0 or of an earlier release is upgraded as usual (see above).

A database upgraded by the fork cannot be used with upstream RackTables code
again. Upstream releases do not know the version `0.23.0-community`: they
refuse to run on such a database with the message that the installation
"seems to be just upgraded to version 0.22.0, while the database version is
0.23.0-community", and their upgrader reports "Upgrade path not found" and
changes nothing. To go back, restore the backup taken before the upgrade.

Databases installed by older releases have two non-unique keys that foreign
keys refer to. MySQL 8.4 refuses that by default, so it cannot restore a dump
of such a database; this release makes the keys unique. To move a database to
MySQL 8.4, upgrade it on the old server first and dump it afterwards. To
restore an older dump on MySQL 8.4, run
`SET SESSION restrict_fk_on_non_standard_key = OFF;` in the session that
imports it, then upgrade.

Passwords of local accounts are now stored with password_hash() instead of
unsalted SHA-1. The upgrade widens the column, and no action is needed: an
existing SHA-1 hash keeps working and is replaced the next time its user logs
in. *Configuration - User accounts - Edit* lists the accounts that still have
an SHA-1 hash; setting a new password there replaces it at once.
A new password can be at most 72 bytes long; an account whose password is
longer keeps its SHA-1 hash until a shorter password is set.

### Upgrading to 0.22.0
As of this release the minimum supported PHP version is 7.0.

### Upgrading to 0.21.2
This version drops support for the `$localreports` global variable, which is
trivial to replace in a local plugin if necessary.

There has been a separation of the `addJS()` and `addCSS()` functions to easily
identify the resource usage.  The original `addJS()` and `addCSS()` functions will
most likely be removed in 0.22.0 as they are deprecated.

- *`addJSText()`/`addCSSText()`*

  These functions should be used to add inline JS/CSS
  not defined in a separate file.

- *`addJSInternal()`/`addCSSInternal()`*

  These functions should be used to add an internal reference to a JS/CSS file
  which is accessed using the `?module=chrome&uri=` for self hosted JS/CSS
  files.  This can be utilised by both the core and plugins.

- *`addJSExternal()`/`addCSSExternal()`*

  These functions should be used to add an external reference to a JS/CSS file
  which hosted on an external site or CDN. This can be utilised by both the core
  and plugins.

Each of these functions expect the first parameter to be the data or URI, and
the second parameter to be an optional group.  By default, the group is set to
'default' and groups are sorted alphabetically.  It should be noted that when
the `addJSInternal()` function is used, it will also add the jQuery and RackTables
common JavaScript functons.

Due to this separation, you will no longer need to supply a TRUE or FALSE to
identify whether the parameter value is text or a URI.  If you rename an
existing `addJS()`/`addCSS()` function call, be sure to remove this parameter.
Failure to do so will mean you are effectively be saying that the group name is
'' (FALSE) or '1' (TRUE).

### Upgrading to 0.21.0

From now on the minimum (oldest) release of PHP that can run RackTables is
5.5.0.

This release introduces a new plugin architecture.  If you experience issues
after the upgrade, try disabling plugins.
Refer to http://wiki.racktables.org/index.php/Plugins
for more information.

### Upgrading to 0.20.11

New `IPV4_TREE_SHOW_UNALLOCATED` configuration option introduced to disable
dsplaying unallocated networks in IPv4 space tree. Setting it also disables
the "knight" feature.

### Upgrading to 0.20.7

From now on the minimum (oldest) release of PHP that can run RackTables is
5.2.10. In particular, to continue running RackTables on CentOS 5 it is
necessary to replace its php* RPM packages with respective php53* packages
before the upgrade (except the JSON package, which PHP 5.3 provides internally).

Database triggers are used for some data consistency measures.  The database
user account must have the 'TRIGGER' privilege, which was introduced in
MySQL 5.1.7.

The `IPV4OBJ_LISTSRC` configuration option is reset to an expression that enables
the IP addressing feature for all object types except those listed.

Tags could now be assigned on the Edit/Properties tab using a text input with
auto-completion. Type a star '*' to view full tag tree in auto-complete menu.
It is worth to add the following line to the permissions script if the
old-fashioned 'Tags' tab is not needed any more:
```
  deny {$tab_tags} # this hides 'Tags' tab
```

This release converts collation of all DB fields to the `utf8_unicode_ci`. This
procedure may take some time, and could fail if there are rows that differ only
by letter case. If this happen, you'll see the failed SQL query in upgrade report
with the "Duplicate entry" error message. Feel free to continue using your
installation. If desired so, you could eliminate the case-duplicating rows
and re-apply the failed query.

### Upgrading to 0.20.6

New `MGMT_PROTOS` configuration option replaces the `TELNET_OBJS_LISTSRC`,
`SSH_OBJS_LISTSRC` and `RDP_OBJS_LISTSRC` options (converting existing settings as
necessary). `MGMT_PROTOS` allows to specify any management protocol for a
particular device list using a RackCode filter. The default value
(`ssh: {$typeid_4}, telnet: {$typeid_8}`) produces `ssh://server.fqdn` for
servers and `telnet://switch.fqdn` for network switches.

### Upgrading to 0.20.5

This release introduces the VS groups feature. VS groups is a new way to store
and display virtual services configuration. There is a new "ipvs" (VS group)
realm. All previously existing VS configuration remains functional and user
is free to convert it to the new format, which displays it in a more natural way
and allows to generate virtual_server_group keepalived configs. To convert a
virtual service to the new format, it is necessary to manually create a VS group
object and assign IP addresses to it. The VS group will display a "Migrate" tab
to convert the old-style VS objects, which can be removed after a successful
conversion.

The old-style VS configuration becomes **deprecated**. Its support will be removed
in a future major release. So it is strongly recommended to convert it to the
new format.

### Upgrading to 0.20.4

Please note that some dictionary items of Cisco Catalyst 2960 series switches
were renamed to meet official Cisco classification:

old name    | new name
------------|---------
2960-48TT   | 2960-48TT-L
2960-24TC   | 2960-24TC-L
2960-24TT   | 2960-24TT-L
2960-8TC    | 2960-8TC-L
2960G-48TC  | 2960G-48TC-L
2960G-24TC  | 2960G-24TC-L
2960G-8TC   | 2960G-8TC-L
C2960-24    | C2960-24-S
C2960G-24PC | C2960-24PC-L

The `DATETIME_FORMAT` configuration option used in setting date and time output
format now uses a [different](http://php.net/manual/en/function.strftime.php)
syntax. During upgrade the option is reset to
the default value, which is now %Y-%m-%d (YYYY-MM-DD) per ISO 8601.

This release intoduces two new configuration options:
`REVERSED_RACKS_LISTSRC` and `NEAREST_RACKS_CHECKBOX`.

### Upgrading to 0.20.1

The 0.20.0 release includes a bug that breaks IP networks' capacity displaying on
32-bit architecture machines. To fix this, this release makes use of PHP's BC
Math module. It is a new reqiurement. Most PHP distributions have this module
already enabled, but if yours does not - you need yo recompile PHP.

Security context of 'ipaddress' page now includes tags from the network
containing an IP address. This means that you should audit your permission rules
to check there is no unintended allows of changing IPs based on network's
tagset. Example:
```
	allow {client network} and {New York}
```
This rule now not only allows any operation on NY client networks, but also any
operation with IP addresses included in those networks. To fix this, you should
change the rule this way:
```
	allow {client network} and {New York} and not {$page_ipaddress}
```

### Upgrading to 0.20.0

WARNING: This release have too many internal changes, some of them were waiting
more than a year to be released. So this release is considered "BETA" and is
recommended only to curiuos users, who agree to sacrifice the stability to the
progress.

Racks and Rows are now stored in the database as Objects. The RackObject table
was renamed to Object. SQL views were created to ease the migration of custom
reports and scripts.

New plugins engine instead of `local.php` file. To make your own code stored in
`local.php` work, you must move the `local.php` file into the `plugins/` directory.
The name of this file does not matter any more. You also can store multiple
files in that dir, separate your plugins by features, share them and try the
plugins from other people just placing them into `plugins/` dir, no more merging.

* `$path_to_local_php` variable has no special meaning any more.
* `$racktables_confdir` variable is now used only to search for `secret.php` file.
* `$racktables_plugins_dir` is a new overridable special variable pointing to `plugins/` directory.

Beginning with this version it is possible to delete IP prefixes, VLANs, Virtual
services and RS pools from within theirs properties tab. So please inspect your
permissions rules to assure there are no undesired allows for deletion of these
objects. To ensure this, you could try this code in the beginning of permissions
script:
```
allow {userid_1} and {$op_del}
deny {$op_del} and ({$tab_edit} or {$tab_properties})
```

Hardware gateways engine was rewritten in this version of RackTables. This means
that the file `gateways/deviceconfig/switch.secrets.php` is not used any more. To
get information about configuring connection properties and credentials in a new
way please read [this](http://wiki.racktables.org/index.php/Gateways).

This also means that recently added features based on old API (D-Link switches
and Linux gateway support contributed by Ilya Evseev) are not working any more
and waiting to be forward-ported to new gateways API. Sorry for that.

Two new config variables appeared in this version:
  - `SEARCH_DOMAINS`. Comma-separated list of DNS domains that are considered
    "base" for your network. If RackTables search engine finds multiple objects
    based on your search input, but there is only one that has FQDN consisting of
    your input and one of these search domains, you will be redirected to this
    object and other results will be discarded. Such behavior was unconditional
    since 0.19.3, which caused many objections from users. So welcome this
    config var.
  - `QUICK_LINK_PAGES`. Comma-separated list of RackTables pages to display links
    to them on top. Each user could have his own list.

Also some of config variables have changed their default values in this version.
This means that upgrade script will change their values if you have them in
previous default state. This could be inconvenient, but it is the most effective
way to encourage users to use new features. If this behavior is not what you
want, simply revert these variables' values:

variable                | old         | new   | comment
------------------------|-------------|-------|--------
`SHOW_LAST_TAB`         | no          | yes
`IPV4_TREE_SHOW_USAGE`  | yes         | no    | Networks' usage is still available by click.
`IPV4LB_LISTSRC`        | {$typeid_4} | false
`FILTER_DEFAULT_ANDOR`  | or          | and   | This implicitly enables the feature of dynamic tree shrinking.
`FILTER_SUGGEST_EXTRA`  | no          | yes   | Yes, we have extra logical filters!
`IPV4_TREE_RTR_AS_CELL` | yes         | no    | Display routers as simple text, not cell.

Also please note that variable `IPV4_TREE_RTR_AS_CELL` now has third special value
besides 'yes' and 'no': 'none'. Use 'none' value if you are experiencing low
performance on IP tree page. It will completely disable IP ranges scan for
used/spare IPs and the speed of IP tree will increase radically. The price is
you will not see the routers in IP tree at all.
