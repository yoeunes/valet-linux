<?php

class MoodleValetDriver extends BasicValetDriver
{
    protected $isStyleUri = false;
    protected $baseUri = '';
    protected $moodleStaticScripts = [
        'styles.php',
        'javascript.php',
        'jquery.php',
        'requirejs.php',
        'font.php',
        'image.php',
        'yui_combo.php',
        'pluginfile.php',
        'draftfile.php'
    ];
    protected $sitePath = '';
    protected $siteName = '';
    protected $uri = '';
    /**
     * Determine if the driver serves the request.
     *
     * @param  string  $sitePath
     * @param  string  $siteName
     * @param  string  $uri
     * @return bool
     */
    public function serves($sitePath, $siteName, $uri)
    {
        $this->sitePath = $sitePath;
        $this->siteName = $siteName;
        $this->uri = $uri;

        if (
            file_exists($sitePath.'/config-dist.php')
            && file_exists($sitePath.'/course')
            && file_exists($sitePath.'/grade')
        ) {
            return true;
        }
    }
    
    /**
     * Determine if the incoming request is for a static file.
     *
     * @param  string  $sitePath
     * @param  string  $siteName
     * @param  string  $uri
     * @return string|false
     */
    public function isStaticFile($sitePath, $siteName, $uri)
    {
        if (file_exists($staticFilePath = $sitePath.$uri))
        {
            return $staticFilePath;
        }


        return false;
    }


    public function mutateUri($uri) {
        foreach($this->moodleStaticScripts as $script) {
            if(preg_match('/'.$script.'/i', $uri) && !preg_match('/'.$script.'$/i', $uri)) {
                $this->isStyleUri = true;
                $pos = strpos($uri, $script);
                $length = strlen($script);
                $this->baseUri = substr($uri, 0, $length + $pos);

                return substr($uri, $length + $pos);
            }
        }

        if(
            empty($uri)
            || (
                !preg_match('/.php/i', $uri)
                && !preg_match('/.html/i', $uri)
                && !preg_match('/.js$/i', $uri)
                && !preg_match('/.css$/i', $uri)
                && !preg_match('/.jpe?g/i', $uri)
                && !preg_match('/.png/i', $uri)
                && !preg_match('/.gif/i', $uri)
                && !preg_match('/.svg/i', $uri)
            )
        ) {
            return "{$uri}/index.php";
        }
        
        return $uri;
    }

    /**
     * Get the fully resolved path to the application's front controller.
     *
     * @param  string  $sitePath
     * @param  string  $siteName
     * @param  string  $uri
     * @return string
     */
    public function frontControllerPath($sitePath, $siteName, $uri)
    {
        $_SERVER['SERVER_SOFTWARE'] = 'PHP';
        $_SERVER['PHP_SELF']    = $uri;
        $_SERVER['SERVER_ADDR'] = '127.0.0.1';

        if(
            (
                empty($uri)
                || (
                    !preg_match('/.php/i', $uri)
                    && !preg_match('/.html/i', $uri)
                    && !preg_match('/.js$/i', $uri)
                )
            )
            && !$this->isStyleUri
            && !$this->isStaticFile($sitePath, $siteName, $uri)
        ) {
            return $this->asPhpIndexFileInDirectory($sitePath, $uri);
        }

        if($this->isStyleUri) {
            $_SERVER['PATH_INFO'] = $uri;
            return $sitePath.$this->baseUri;
        }

        return $sitePath.$uri;
    }
}
