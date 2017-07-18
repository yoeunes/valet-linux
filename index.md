<p align="center"><img src="https://cdn.rawgit.com/wiki/cpriego/valet-linux/images/valet.svg"></p>

### WARNING
Please **do not, under any circumstance, install valet with root OR the `sudo` command**. Kittens could die.

### Introduction

Valet *Linux* is a Laravel development environment for Linux minimalists. No Vagrant, no `/etc/hosts` file. You can even share your sites publicly using local tunnels. _Yeah, we like it too._

Valet *Linux* configures your system to always run Nginx in the background when your machine starts. Then, using [DnsMasq](https://en.wikipedia.org/wiki/Dnsmasq), Valet proxies all requests on the `*.dev` domain to point to sites installed on your local machine.

In other words, a blazing fast Laravel development environment that uses roughly 7mb of RAM. Valet *Linux* isn't a complete replacement for Vagrant or Homestead, but provides a great alternative if you want flexible basics, prefer extreme speed, or are working on a machine with a limited amount of RAM.

Out of the box, Valet support includes, but is not limited to:

- [Laravel](https://laravel.com)
- [Lumen](https://lumen.laravel.com)
- [Symfony](https://symfony.com)
- [Zend](https://framework.zend.com)
- [CakePHP 3](https://cakephp.org)
- [WordPress](https://wordpress.org)
- [Bedrock](https://roots.io/bedrock/)
- [Craft](https://craftcms.com)
- [Statamic](https://statamic.com)
- [Jigsaw](http://jigsaw.tighten.co)
- Static HTML

However, you may extend Valet with your own [custom drivers](https://laravel.com/docs/5.4/valet#custom-valet-drivers).

<a name="valet-or-homestead"></a>
### Valet Or Homestead

As you may know, Laravel offers [Homestead](https://laravel.com/docs/5.4/homestead), another local Laravel development environment. Homestead and Valet differ in regards to their intended audience and their approach to local development. Homestead offers an entire Ubuntu virtual machine with automated Nginx configuration. Homestead is a wonderful choice if you want a fully virtualized Linux development environment or are on Windows.

Valet _Linux_ requires you to install PHP and a database server directly onto your local machine. This is easily achieved by using your package manager. Valet provides a blazing fast local development environment with minimal resource consumption, so it's great for developers who only require PHP / MySQL and do not need a fully virtualized development environment.

Both Valet and Homestead are great choices for configuring your Laravel development environment. Which one you choose will depend on your personal taste and your team's needs.

## Installation

__Valet *Linux* is installed as a `composer` global package. You need to have composer installed in your system and ideally have the composer global tools added to your `PATH`.__

__Before installation, you should review your system specific requirements and make sure that no other programs such as Apache or Nginx are binding to your local machine's port 80.__

__Please remember to check the [F.A.Q.](https://github.com/cpriego/valet-linux/wiki/FAQ) for common questions or errors before posting an issue.__

 - Install Valet with Composer via `composer global require cpriego/valet-linux`.
 - Run the `valet install` command. This will configure and install Valet and DnsMasq, and register Valet's daemon to launch when your system starts.

Once Valet is installed, try pinging any `*.dev` domain on your terminal using a command such as `ping foobar.dev`. If Valet is installed correctly you should see this domain responding on `127.0.0.1`.

Valet will automatically start its daemon each time your machine boots. There is no need to run `valet start` or `valet install` ever again once the initial Valet installation is complete.

#### Using Another Domain

By default, Valet serves your projects using the `.dev` TLD. If you'd like to use another domain, you can do so using the `valet domain tld-name` command.

For example, if you'd like to use `.app` instead of `.dev`, run `valet domain app` and Valet will start serving your projects at `*.app` automatically.

<a name="serving-sites"></a>
## Serving Sites

Once Valet is installed, you're ready to start serving sites. Valet provides two commands to help you serve your Laravel sites: `park` and `link`.

<a name="the-park-command"></a>
**The `park` Command**

- Create a new directory on your machine by running something like `mkdir ~/Sites`. Next, `cd ~/Sites` and run `valet park`. This command will register your current working directory as a path that Valet should search for sites.
- Next, create a new Laravel site within this directory: `laravel new blog`.
- Open `http://blog.dev` in your browser.

**That's all there is to it.** Now, any Laravel project you create within your "parked" directory will automatically be served using the `http://folder-name.dev` convention.

<a name="the-link-command"></a>
**The `link` Command**

The `link` command may also be used to serve your Laravel sites. This command is useful if you want to serve a single site in a directory and not the entire directory.


- To use the command, navigate to one of your projects and run `valet link app-name` in your terminal. Valet will create a symbolic link in `~/.valet/Sites` which points to your current working directory.
- After running the `link` command, you can access the site in your browser at `http://app-name.dev`.

To see a listing of all of your linked directories, run the `valet links` command. You may use `valet unlink app-name` to destroy the symbolic link.

> You can use `valet link` to serve the same project from multiple (sub)domains. To add a subdomain or another domain to your project run `valet link subdomain.app-name` from the project folder.

<a name="changing-tld"></a>
**Changing domain TLD**

After installation all valet sites are assigned the `.dev` TLD, so if you have a folder named `foo` it will have the `foo.dev` URL.

If you would like to change the TLD for all your valet sites use the `domain` command.

To change the domain TLD to `.app` simply run:
```
valet domain .app
```

To see current TLD assigned just run the command without options:
```
valet domain
```

<a name="changing-port"></a>
**Changing Valet's Nginx port**

Valet, by default, binds Nginx to port `80`. That's rarely a problem for that port is usually free. But, if you installed another Web server (like Apache) prior Valet, chances are that port is no longer available.

If that's the case you can use the `port` command.

To change Valet's Nginx port to `8888` run:
```
valet port 8888
```

To see the configured port run:
```
valet port
```

<a name="securing-sites"></a>
**Securing Sites With TLS**

By default, Valet serves sites over plain HTTP. However, if you would like to serve a site over encrypted TLS using HTTP/2, use the `secure` command. For example, if your site is being served by Valet on the `laravel.dev` domain, you should run the following command to secure it:

    valet secure laravel

To "unsecure" a site and revert back to serving its traffic over plain HTTP, use the `unsecure` command. Like the `secure` command, this command accepts the host name that you wish to unsecure:

    valet unsecure laravel

<a name="sharing-sites"></a>
## Sharing Sites

Valet even includes a command to share your local sites with the world. No additional software installation is required once Valet is installed.

To share a site, navigate to the site's directory in your terminal and run the `valet share` command. A publicly accessible URL will be inserted into your clipboard and is ready to paste directly into your browser. That's it.

To stop sharing your site, hit `Control + C` to cancel the process.

> `valet share` does not currently support sharing sites that have been secured using the `valet secure` command.

<a name="other-valet-commands"></a>
## Other Valet Commands

Command  | Description
------------- | -------------
`valet forget` | Remove a "parked" directory from the list.
`valet paths` | View all of your "parked" paths.
`valet restart` | Restart the Valet daemon.
`valet start` | Start the Valet daemon.
`valet stop` | Stop the Valet daemon.
`valet status` | View Valet services status.
`valet uninstall` | Uninstall the Valet daemon entirely.

## Update

To update your Valet package just run: `composer global update`

