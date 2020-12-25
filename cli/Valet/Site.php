<?php

namespace Valet;

class Site
{
    public $config;
    public $cli;
    public $files;

    /**
     * Create a new Site instance.
     *
     * @param Configuration $config
     * @param CommandLine   $cli
     * @param Filesystem    $files
     */
    public function __construct(Configuration $config, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
        $this->config = $config;
    }

    /**
     * Get the name of the site.
     *
     * @param  string|null $name
     * @return string
     */
    private function getRealSiteName($name)
    {
        if (! is_null($name)) {
            return $name;
        }

        if (is_string($link = $this->getLinkNameByCurrentDir())) {
            return $link;
        }

        return basename(getcwd());
    }

    /**
     * Get link name based on the current directory.
     *
     * @return null|string
     */
    private function getLinkNameByCurrentDir()
    {
        $count = count($links = $this->links()->where('path', getcwd()));

        if ($count == 1) {
            return $links->shift()['site'];
        }

        if ($count > 1) {
            throw new DomainException("There are {$count} links related to the current directory, please specify the name: valet unlink <name>.");
        }
    }

    /**
     * Get the real hostname for the given path, checking links.
     *
     * @param string $path
     * @return string|null
     */
    public function host($path)
    {
        foreach ($this->files->scandir($this->sitesPath()) as $link) {
            if (realpath($this->sitesPath() . '/' . $link) === $path) {
                return $link;
            }
        }

        return basename($path);
    }

    /**
     * Link the current working directory with the given name.
     *
     * @param string $target
     * @param string $link
     * @return string
     */
    public function link($target, $link)
    {
        $this->files->ensureDirExists(
            $linkPath = $this->sitesPath(),
            user()
        );

        $this->config->prependPath($linkPath);

        $this->files->symlinkAsUser($target, $linkPath . '/' . $link);

        return $linkPath . '/' . $link;
    }

    /**
     * Pretty print out all links in Valet.
     *
     * @return \Illuminate\Support\Collection
     */
    public function links()
    {
        $certsPath = $this->certificatesPath();

        $this->files->ensureDirExists($certsPath, user());

        $certs = $this->getCertificates($certsPath);

        return $this->getSites($this->sitesPath(), $certs);
    }

    /**
     * Pretty print out all parked links in Valet
     *
     * @return \Illuminate\Support\Collection
     */
    public function parked()
    {
        $certs = $this->getCertificates();

        $links = $this->getSites($this->sitesPath(), $certs);

        $config = $this->config->read();
        $parkedLinks = collect();
        foreach (array_reverse($config['paths']) as $path) {
            if ($path === $this->sitesPath()) {
                continue;
            }

            // Only merge on the parked sites that don't interfere with the linked sites
            $sites = $this->getSites($path, $certs)->filter(function ($site, $key) use ($links) {
                return !$links->has($key);
            });

            $parkedLinks = $parkedLinks->merge($sites);
        }

        return $parkedLinks;
    }

    /**
     * Get all sites which are proxies (not Links, and contain proxy_pass directive)
     *
     * @return \Illuminate\Support\Collection
     */
    public function proxies()
    {
        $dir = $this->nginxPath();
        $tld = $this->config->read()['domain'];
        $links = $this->links();
        $certs = $this->getCertificates();

        if (! $this->files->exists($dir)) {
            return collect();
        }

        $proxies = collect($this->files->scandir($dir))
        ->filter(function ($site, $key) use ($tld) {
            // keep sites that match our TLD
            return ends_with($site, '.'.$tld);
        })->map(function ($site, $key) use ($tld) {
            // remove the TLD suffix for consistency
            return str_replace('.'.$tld, '', $site);
        })->reject(function ($site, $key) use ($links) {
            return $links->has($site);
        })->mapWithKeys(function ($site) {
            $host = $this->getProxyHostForSite($site) ?: '(other)';
            return [$site => $host];
        })->reject(function ($host, $site) {
            // If proxy host is null, it may be just a normal SSL stub, or something else; either way we exclude it from the list
            return $host === '(other)';
        })->map(function ($host, $site) use ($certs, $tld) {
            $secured = $certs->has($site);
            $url = ($secured ? 'https': 'http').'://'.$site.'.'.$tld;

            return [
                'site' => $site,
                'secured' => $secured ? ' X': '',
                'url' => $url,
                'path' => $host,
            ];
        });

        return $proxies;
    }

    /**
     * Identify whether a site is for a proxy by reading the host name from its config file.
     *
     * @param string $site Site name without TLD
     * @param string $configContents Config file contents
     * @return string|null
     */
    public function getProxyHostForSite($site, $configContents = null)
    {
        $siteConf = $configContents ?: $this->getSiteConfigFileContents($site);

        if (empty($siteConf)) {
            return null;
        }

        $host = null;
        if (preg_match('~proxy_pass\s+(?<host>https?://.*)\s*;~', $siteConf, $patterns)) {
            $host = trim($patterns['host']);
        }
        return $host;
    }

    public function getSiteConfigFileContents($site, $suffix = null)
    {
        $config = $this->config->read();
        $suffix = $suffix ?: '.'.$config['domain'];
        $file = str_replace($suffix,'',$site).$suffix;
        return $this->files->exists($this->nginxPath($file)) ? $this->files->get($this->nginxPath($file)) : null;
    }

    /**
     * Get all certificates from config folder.
     *
     * @param string $path
     * @return \Illuminate\Support\Collection
     */
    public function getCertificates($path = null)
    {
        $path = $path ?: $this->certificatesPath();

        $this->files->ensureDirExists($path, user());

        $config = $this->config->read();

        return collect($this->files->scandir($path))->filter(function ($value, $key) {
            return ends_with($value, '.crt');
        })->map(function ($cert) use ($config) {
            $certWithoutSuffix = substr($cert, 0, -4);
            $trimToString = '.';

            // If we have the cert ending in our tld strip that tld specifically
            // if not then just strip the last segment for  backwards compatibility.
            if (ends_with($certWithoutSuffix, $config['domain'])) {
                $trimToString .= $config['domain'];
            }

            return substr($certWithoutSuffix, 0, strrpos($certWithoutSuffix, $trimToString));
        })->flip();

//        return collect($this->files->scanDir($path))->filter(function ($value, $key) {
//            return ends_with($value, '.crt');
//        })->map(function ($cert) {
//            return substr($cert, 0, -9);
//        })->flip();
    }

    public function getLinks($path, $certs)
    {
        return $this->getSites($path, $certs);
    }

    /**
     * Get list of links and present them formatted.
     *
     * @param string                         $path
     * @param \Illuminate\Support\Collection $certs
     * @return \Illuminate\Support\Collection
     */
    public function getSites($path, $certs)
    {
        $config = $this->config->read();

        $this->files->ensureDirExists($path, user());

        $httpPort = $this->httpSuffix();
        $httpsPort = $this->httpsSuffix();

        return collect($this->files->scandir($path))->mapWithKeys(function ($site) use ($path) {
            $sitePath = $path.'/'.$site;

            if ($this->files->isLink($sitePath)) {
                $realPath = $this->files->readLink($sitePath);
            } else {
                $realPath = $this->files->realpath($sitePath);
            }
            return [$site => $realPath];
        })->filter(function ($path) {
            return $this->files->isDir($path);
        })->map(function ($path, $site) use ($certs, $config) {
            $secured = $certs->has($site);
            $url = ($secured ? 'https': 'http').'://'.$site.'.'.$config['domain'];

            return [
                'site' => $site,
                'secured' => $secured ? ' X': '',
                'url' => $url,
                'path' => $path,
            ];
        });

//        return collect($this->files->scanDir($path))->mapWithKeys(function ($site) use ($path) {
//            return [$site => $this->files->readLink($path . '/' . $site)];
//        })->map(function ($path, $site) use ($certs, $config, $httpPort, $httpsPort) {
//            $secured = $certs->has($site);
//
//            $url = ($secured ? 'https' : 'http') . '://' . $site . '.' . $config['domain'] . ($secured ? $httpsPort : $httpPort);
//
//            return [$site, $secured ? ' X' : '', $url, $path];
//        });
    }

    /**
     * Return http port suffix
     *
     * @return string
     */
    public function httpSuffix()
    {
        $port = $this->config->get('port', 80);

        return ($port == 80) ? '' : ':' . $port;
    }

    /**
     * Return https port suffix
     *
     * @return string
     */
    public function httpsSuffix()
    {
        $port = $this->config->get('https_port', 443);

        return ($port == 443) ? '' : ':' . $port;
    }

    /**
     * Unlink the given symbolic link.
     *
     * @param string $name
     * @return void
     */
    public function unlink($name)
    {
        $name = $this->getRealSiteName($name);

        if ($this->files->exists($path = $this->sitesPath($name))) {
            $this->files->unlink($path);
        }

        return $name;
    }

    /**
     * Remove all broken symbolic links.
     *
     * @return void
     */
    public function pruneLinks()
    {
        $this->files->ensureDirExists($this->sitesPath(), user());

        $this->files->removeBrokenLinksAt($this->sitesPath());
    }

    /**
     * Resecure all currently secured sites with a fresh domain.
     *
     * @param string $oldDomain
     * @param string $domain
     * @return void
     */
    public function resecureForNewDomain($oldDomain, $domain)
    {
        $this->resecureForNewTld($oldDomain, $domain);
    }

    public function resecureForNewTld($oldDomain, $domain)
    {
        if (!$this->files->exists($this->certificatesPath())) {
            return;
        }

        $secured = $this->secured();

        foreach ($secured as $url) {
            $this->unsecure($url);
        }

        foreach ($secured as $url) {
            $newUrl = str_replace('.'.$oldDomain, '.'.$domain, $url);
            $siteConf = $this->getSiteConfigFileContents($url, '.'.$oldDomain);

            if (!empty($siteConf) && strpos($siteConf, '# valet stub: proxy.valet.conf') === 0) {
                // proxy config
                $this->unsecure($url);
                $this->secure($newUrl, $this->replaceOldDomainWithNew($siteConf, $url, $newUrl));
            } else {
                // normal config
                $this->unsecure($url);
                $this->secure($newUrl);
            }
        }
    }

     /**
     * Parse Nginx site config file contents to swap old domain to new.
     *
     * @param  string $siteConf Nginx site config content
     * @param  string $old  Old domain
     * @param  string $new  New domain
     * @return string
     */
    public function replaceOldDomainWithNew($siteConf, $old, $new)
    {
        $lookups = [];
        $lookups[] = '~server_name .*;~';
        $lookups[] = '~error_log .*;~';
        $lookups[] = '~ssl_certificate_key .*;~';
        $lookups[] = '~ssl_certificate .*;~';

        foreach ($lookups as $lookup) {
            preg_match($lookup, $siteConf, $matches);
            foreach ($matches as $match) {
                $replaced = str_replace($old, $new, $match);
                $siteConf = str_replace($match, $replaced, $siteConf);
            }
        }
        return $siteConf;
    }

    /**
     * Get all of the URLs that are currently secured.
     *
     * @return \Illuminate\Support\Collection
     */
    public function secured()
    {
        return collect($this->files->scandir($this->certificatesPath()))
                    ->map(function ($file) {
                        return str_replace(['.key', '.csr', '.crt', '.conf'], '', $file);
                    })->unique()->values()->all();
    }

    /**
     * Secure the given host with TLS.
     *
     * @param string $url
     * @param  string  $siteConf  pregenerated Nginx config file contents
     * @return void
     */
    public function secure($url, $siteConf = null)
    {
        $this->unsecure($url);

        $this->files->ensureDirExists($this->caPath(), user());

        $this->files->ensureDirExists($this->certificatesPath(), user());

        $this->files->ensureDirExists($this->nginxPath(), user());

        $this->createCa();

        $this->createCertificate($url);

        $this->createSecureNginxServer($url, $siteConf);
    }

    /**
     * If CA and root certificates are nonexistent, create them and trust the root cert.
     *
     * @return void
     */
    public function createCa()
    {
        $caPemPath = $this->caPath() . '/LaravelValetCASelfSigned.pem';
        $caKeyPath = $this->caPath() . '/LaravelValetCASelfSigned.key';
        if ($this->files->exists($caKeyPath) && $this->files->exists($caPemPath)) {
            return;
        }
        $oName = 'Laravel Valet CA Self Signed Organization';
        $cName = 'Laravel Valet CA Self Signed CN';
        if ($this->files->exists($caKeyPath)) {
            $this->files->unlink($caKeyPath);
        }
        if ($this->files->exists($caPemPath)) {
            $this->files->unlink($caPemPath);
        }
        $this->cli->runAsUser(sprintf(
            'openssl req -new -newkey rsa:2048 -days 730 -nodes -x509 -subj "/O=%s/commonName=%s/organizationalUnitName=Developers/emailAddress=%s/" -keyout "%s" -out "%s"',
            $oName,
            $cName,
            'rootcertificate@laravel.valet',
            $caKeyPath,
            $caPemPath
        ));
    }

    /**
     * Create and trust a certificate for the given URL.
     *
     * @param string $url
     * @return void
     */
    public function createCertificate($url)
    {
        $caPemPath = $this->caPath() . '/LaravelValetCASelfSigned.pem';
        $caKeyPath = $this->caPath() . '/LaravelValetCASelfSigned.key';
        $caSrlPath = $this->caPath() . '/LaravelValetCASelfSigned.srl';
        $keyPath = $this->certificatesPath() . '/' . $url . '.key';
        $csrPath = $this->certificatesPath() . '/' . $url . '.csr';
        $crtPath = $this->certificatesPath() . '/' . $url . '.crt';
        $confPath = $this->certificatesPath() . '/' . $url . '.conf';

        $this->buildCertificateConf($confPath, $url);
        $this->createPrivateKey($keyPath);
        $this->createSigningRequest($url, $keyPath, $csrPath, $confPath);

        $caSrlParam = ' -CAcreateserial';
        if ($this->files->exists($caSrlPath)) {
            $caSrlParam = ' -CAserial ' . $caSrlPath;
        }
        $this->cli->runAsUser(sprintf(
            'openssl x509 -req -sha256 -days 365 -CA "%s" -CAkey "%s"%s -in "%s" -out "%s" -extensions v3_req -extfile "%s"',
            $caPemPath,
            $caKeyPath,
            $caSrlParam,
            $csrPath,
            $crtPath,
            $confPath
        ));

        $this->trustCertificate($crtPath, $url);
    }

    /**
     * Create the private key for the TLS certificate.
     *
     * @param string $keyPath
     * @return void
     */
    public function createPrivateKey($keyPath)
    {
        $this->cli->runAsUser(sprintf('openssl genrsa -out %s 2048', $keyPath));
    }

    /**
     * Create the signing request for the TLS certificate.
     *
     * @param string $keyPath
     * @return void
     */
    public function createSigningRequest($url, $keyPath, $csrPath, $confPath)
    {
        $this->cli->runAsUser(sprintf(
            'openssl req -new -key %s -out %s -subj "/C=US/ST=MN/O=Valet/localityName=Valet/commonName=%s/organizationalUnitName=Valet/emailAddress=valet/" -config %s -passin pass:',
            $keyPath,
            $csrPath,
            $url,
            $confPath
        ));
    }

    /**
     * Build the SSL config for the given URL.
     *
     * @param string $url
     * @return string
     */
    public function buildCertificateConf($path, $url)
    {
        $config = str_replace('VALET_DOMAIN', $url, $this->files->get(__DIR__ . '/../stubs/openssl.conf'));
        $this->files->putAsUser($path, $config);
    }

    /**
     * Trust the given certificate file in the Mac Keychain.
     *
     * @param string $crtPath
     * @return void
     */
    public function trustCertificate($crtPath, $url)
    {
        $this->cli->run(sprintf(
            'certutil -d sql:$HOME/.pki/nssdb -A -t TC -n "%s" -i "%s"',
            $url,
            $crtPath
        ));

        $this->cli->run(sprintf(
            'certutil -d $HOME/.mozilla/firefox/*.default -A -t TC -n "%s" -i "%s"',
            $url,
            $crtPath
        ));
    }

    /**
     * @param $url
     */
    public function createSecureNginxServer($url, $siteConf = null)
    {
        $this->files->putAsUser(
            VALET_HOME_PATH . '/Nginx/' . $url,
            $this->buildSecureNginxServer($url, $siteConf)
        );
    }

    /**
     * Build the TLS secured Nginx server for the given URL.
     *
     * @param string $url
     * @return string
     */
    public function buildSecureNginxServer($url, $siteConf)
    {
        if ($siteConf === null) {
            $siteConf = $this->files->get(__DIR__.'/../stubs/secure.valet.conf');
        }

        return str_array_replace(
            [
                'VALET_HOME_PATH' => VALET_HOME_PATH,
                'VALET_SERVER_PATH' => VALET_SERVER_PATH,
                'VALET_STATIC_PREFIX' => VALET_STATIC_PREFIX,
                'VALET_SITE' => $url,
                'VALET_CERT' => $this->certificatesPath($url, 'crt'),
                'VALET_KEY' => $this->certificatesPath($url, 'key'),
                'VALET_HTTP_PORT' => $this->config->get('port', 80),
                'VALET_HTTPS_PORT' => $this->config->get('https_port', 443),
                'VALET_REDIRECT_PORT' => $this->httpsSuffix(),
            ],
            $siteConf
        );
    }

    /**
     * Unsecure the given URL so that it will use HTTP again.
     *
     * @param string $url
     * @return void
     */
    public function unsecure($url)
    {
        if ($this->files->exists($this->certificatesPath() . '/' . $url . '.crt')) {
            $this->files->unlink(VALET_HOME_PATH . '/Nginx/' . $url);

            $this->files->unlink($this->certificatesPath() . '/' . $url . '.conf');
            $this->files->unlink($this->certificatesPath() . '/' . $url . '.key');
            $this->files->unlink($this->certificatesPath() . '/' . $url . '.csr');
            $this->files->unlink($this->certificatesPath() . '/' . $url . '.crt');

            $this->cli->run(sprintf('certutil -d sql:$HOME/.pki/nssdb -D -n "%s"', $url));
            $this->cli->run(sprintf('certutil -d $HOME/.mozilla/firefox/*.default -D -n "%s"', $url));
        }
    }

    function unsecureAll()
    {
        $tld = $this->config->read()['domain'];

        $secured = $this->parked()
            ->merge($this->links())
            ->sort()
            ->where('secured', ' X');

        if ($secured->count() === 0) {
            return info('No sites to unsecure. You may list all servable sites or links by running <comment>valet parked</comment> or <comment>valet links</comment>.');
        }

        info('Attempting to unsecure the following sites:');
        table(['Site', 'SSL', 'URL', 'Path'], $secured->toArray());

        foreach ($secured->pluck('site') as $url) {
            $this->unsecure($url . '.' . $tld);
        }

        $remaining = $this->parked()
            ->merge($this->links())
            ->sort()
            ->where('secured', ' X');
        if ($remaining->count() > 0) {
            warning('We were not succesful in unsecuring the following sites:');
            table(['Site', 'SSL', 'URL', 'Path'], $remaining->toArray());
        }
        info('unsecure --all was successful.');
    }

    /**
     * Build the Nginx proxy config for the specified domain
     *
     * @param  string  $url The domain name to serve
     * @param  string  $host The URL to proxy to, eg: http://127.0.0.1:8080
     * @return string
     */
    public function proxyCreate($url, $host)
    {
        if (!preg_match('~^https?://.*$~', $host)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid URL', $host));
        }

        $tld = $this->config->read()['domain'];
        if (!ends_with($url, '.'.$tld)) {
            $url .= '.'.$tld;
        }

        $siteConf = $this->files->get(__DIR__.'/../stubs/proxy.valet.conf');

        $siteConf = str_replace(
            ['VALET_HOME_PATH', 'VALET_SERVER_PATH', 'VALET_STATIC_PREFIX', 'VALET_SITE', 'VALET_PROXY_HOST'],
            [VALET_HOME_PATH, VALET_SERVER_PATH, VALET_STATIC_PREFIX, $url, $host],
            $siteConf
        );

        $this->secure($url, $siteConf);

        info('Valet will now proxy [https://'.$url.'] traffic to ['.$host.'].');
    }

    /**
     * Unsecure the given URL so that it will use HTTP again.
     *
     * @param  string  $url
     * @return void
     */
    public function proxyDelete($url)
    {
        $tld = $this->config->read()['domain'];
        if (!ends_with($url, '.'.$tld)) {
            $url .= '.'.$tld;
        }

        $this->unsecure($url);
        $this->files->unlink($this->nginxPath($url));

        info('Valet will no longer proxy [https://'.$url.'].');
    }

    /**
     * Regenerate all secured file configurations
     *
     * @return void
     */
    public function regenerateSecuredSitesConfig()
    {
        $this->secured()->each(function ($url) {
            $this->createSecureNginxServer($url);
        });
    }

    /**
     * Get the path to the linked Valet sites.
     *
     * @return string
     */
    public function sitesPath($link = null)
    {
        return $this->valetHomePath().'/Sites'.($link ? '/'.$link : '');
    }

    /**
     * Get the path to the Valet CA certificates.
     *
     * @return string
     */
    public function caPath($caFile = null)
    {
        return $this->valetHomePath().'/CA'.($caFile ? '/'.$caFile : '');
    }

    /**
     * Get the path to Nginx site configuration files.
     */
    function nginxPath($additionalPath = null)
    {
        return $this->valetHomePath().'/Nginx'.($additionalPath ? '/'.$additionalPath : '');
    }

    /**
     * Get the path to the Valet TLS certificates.
     *
     * @return string
     */
    public function certificatesPath($url = null, $extension = null)
    {
        $url = $url ? '/'.$url : '';
        $extension = $extension ? '.'.$extension : '';

        return $this->valetHomePath().'/Certificates'.$url.$extension;
    }

    public function valetHomePath()
    {
        return VALET_HOME_PATH;
    }
}
