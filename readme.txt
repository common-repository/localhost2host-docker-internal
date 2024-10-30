=== localhost2host.docker.internal ===
Contributors: enomoto celtislab
Tags: localhost, host.docker.internal, wp-env, site health, loopback
Requires at least: 6.3
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

For Docker wp development: Replace loopback requests to localhost with host.docker.internal

== Description ==

Prevents loopback request errors in WordPress environments using Docker such as wp-env.

Replace requests to localhost with host.docker.internal, and if https, change to http and turn off sslverify.

There are no settings. Just enable this plugin.


== Installation ==

1. Upload the `localhost2host-docker-internal` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the `Plugins` menu in WordPress
