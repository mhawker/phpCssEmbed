<?php
/**
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *  08/08/12 15:22
 */

namespace CssEmbed;

/**
 * CssEmbed
 *
 * @author Pierre Tachoire <pierre.tachoire@gmail.com>
 */
class CssEmbed
{

    const SEARCH_PATTERN = "%url\\(['\" ]*((?!data:)[^'\" ]+)['\" ]*\\)%U";
    const DATA_URI_PATTERN = "url(data:%s;base64,%s)";
    const URL_URI_PATTERN = "url('%s')";
    const MIME_MAGIC_URL = 'http://svn.apache.org/repos/asf/httpd/httpd/trunk/docs/conf/mime.types';
    const EMBED_FONTS = 1;
    const EMBED_SVG = 2;
    const URL_ON_ERROR = 4;
    const HTTP_DEFAULT_HTTPS = 1;
    const HTTP_EMBED_SCHEME = 2;
    const HTTP_EMBED_URL_ONLY = 4;

    /** @var string the root directory for finding assets */
    protected $root_dir;

    /** @var string the path to the local mime.magic database */
    protected $mime_magic_path = null;

    /** @var integer flags that modify behavior, embed SVG by default for BC */
    protected $flags = 2;

    /** @var bool enable HTTP asset fetching */
    protected $http_enabled = false;

    /** @var integer flags that modify behavior in HTTP only */
    protected $http_flags = 0;

    /**
     * @param string $root_dir
     */
    public function setRootDir($root_dir)
    {
        $this->root_dir = $root_dir;
    }

    /**
     * Set embedding options. Flags:
     *
     *     - CssEmbed::EMBED_FONTS: embedding fonts will usually break them
     *       in most browsers.  Enable this flag to force the embed. WARNING:
     *       this flag is currently not unit tested, but seems to work.
     *     - CssEmbed::EMBED_SVG: SVG is often used as a font face; however
     *       including these in a stylesheet will cause it to bloat for browsers
     *       that don't use it.  SVGs will be embedded by default.
     *     - CssEmbed::URL_ON_ERROR: if there is an error fetching an asset,
     *       embed a URL (or best guess at URL) instead of throwing an exception
     *
     * @param integer $flags
     *
     * @return void
     */
    public function setOptions($flags)
    {
        $this->flags = $flags;
    }

    /**
     * Enable embedding assets over HTTP, or processing stylesheets from HTTP
     * locations. Available flags:
     *
     *     - CssEmbed::HTTP_DEFAULT_HTTPS: when HTTP assets are enabled, use
     *       HTTPS for URLs with no scheme
     *     - CssEmbed::HTTP_EMBED_SCHEME: By default, assets that are converted
     *       to URLs instead of data urls have no scheme (eg, "//example.com").
     *       This is better for stylesheets that are maybe served over http or
     *       https, but it will break stylesheets served from a local HTML file.
     *       Set this option to force the schema (eg, "http://example.com").
     *     - CssEmbed::HTTP_EMBED_URL_ONLY: do not convert assets to data URLs,
     *       only the fully qualified URL.
     *
     * @note this method will turn the options URL_ON_ERROR on and EMBED_SVG
     * off. You will need to use setOptions() after this method to change that.
     *
     * @param bool $enable
     * @param int $flags flags that modify HTTP behaviour
     * @return void
     */
    public function enableHttp($enable = true, $flags = 0)
    {
        $this->http_enabled = (bool) $enable;
        $this->flags = $this->flags|self::URL_ON_ERROR;
        $this->flags = $this->flags & (~ self::EMBED_SVG);
        $this->http_flags = (int) $flags;
    }

    /**
     * Enable the functionality to compare mimes against a custom mime.types file.
     *
     * @param string $path the path to the mime types file
     * @param bool $create download and save the Apache mime types file if the
     * specified path does not exist
     * @throws \InvalidArgumentException if the mime file does not exist and
     * cannot be created.
     * @return void
     */
    public function enableEnhancedMimeTypes(
        $path = '/tmp/cssembed.mime.types',
        $create = true
    ) {
        if (!file_exists($path) && $create) {
            $this->createMimesFile($path);
        }
        if (!file_exists($path)) {
            $this->error('mime.types does not exist and cannot be created: "%s"', $path);
        }
        if (!is_readable($path) || !is_file($path)) {
            $this->error('Invalid mime.types file: "%s"', $path);
        }
        $this->mime_magic_path = $path;
    }

    /**
     * @param string $css_file
     * @return null|string
     * @throws \InvalidArgumentException
     */
    public function embedCss($css_file)
    {
        $this->setRootDir(dirname($css_file));
        $content = @file_get_contents($css_file);
        if ($content === false) {
            throw new \InvalidArgumentException(sprintf('Cannot read file %s', $css_file));
        }
        return $this->embedString($content);
    }

    /**
     * @param $content
     * @return mixed
     */
    public function embedString($content)
    {
        return preg_replace_callback(
            self::SEARCH_PATTERN,
            array($this, 'replace'),
            $content
        );
    }

    /**
     * preg_replace_callback callback for embedString.
     *
     * @param array $matches
     * @return string
     */
    protected function replace($matches)
    {
        if ($asset = $this->fetchAsset($matches[1])) {
            if ($this->assetIsEmbeddable($asset)) {
                return sprintf(
                    self::DATA_URI_PATTERN,
                    $asset['mime'],
                    base64_encode($asset['content'])
                );
            }
        }
        if ($url = $this->fetchAssetUrl($matches[1])) {
            return sprintf(self::URL_URI_PATTERN, $url);
        }
        return $matches[0];    
    }

    /**
     * Fetch an asset
     *
     * @param string $path the asset path
     * @return array|false an array with keys 'content' for the file content
     * and 'mime' for the mime type, or FALSE on error
     */
    protected function fetchAsset($path)
    {
        $asset = false;
        if ($this->isHttpAsset($path)) {
            if ($url = $this->resolveAssetUrl($path)) {
                $asset = $this->fetchHttpAsset($url);
            }
        } else {
            if ($absolute_path = $this->resolveAssetPath($path)) {
                $asset = $this->fetchLocalAsset($absolute_path);
            }
        }
        return $asset;
    }
    
    /**
     * Get the URL to an asset as it would be embedded in a stylesheet
     *
     * @param string $path the path to the asset as it appears in the stylesheet
     * @return string $url the URL to the asset
     */
    protected function fetchAssetUrl($path)
    {
        if (!$this->isHttpAsset($path)) {
            return $path;
        }
        $url = $this->resolveAssetUrl($path); 
        if (!($this->http_flags & self::HTTP_EMBED_SCHEME)) {
            $url = preg_replace('/^https?:/', '', $url);
        }
        return $url;
    }

    /**
     * Fetch an asset stored locally in the filesystem
     *
     * @param string $absolute_path the absolute path to the asset
     * @return array same as fetchAsset
     */
    protected function fetchLocalAsset($absolute_path)
    {
        if (!is_file($absolute_path) || !is_readable($absolute_path)) {
            $this->error('Cannot read file %s', $absolute_path);
            return false;
        }
        $content = file_get_contents($absolute_path);

        $mime = $this->getLocalAssetMime($absolute_path);

        return compact('content', 'mime');
    }

    /**
     * Find the mime for a file on the local file system
     *
     * @param string $absolute_path
     * @return false|string the mime type, or false if not found
     */
    protected function getLocalAssetMime($absolute_path)
    {
        if ($mime = $this->detectMime($absolute_path)) {
            return $mime;
        }

        if ($mime = @mime_content_type($absolute_path)) {
            return $mime;
        }

        if ($info = @getimagesize($absolute_path)) {
            return $info['mime'];
        }

        return 'application/octet-stream';
    }

    /**
     * Fetch an asset stored remotely over HTTP
     *
     * @param string $url the url to the asset
     * @return array same as fetchAsset
     */
    protected function fetchHttpAsset($url)
    {
        if ($this->http_flags & self::HTTP_EMBED_URL_ONLY) {
            return false;
        }
        if (false === ($content = @file_get_contents($url))) {
            $this->error('Cannot read url %s', $url);
            return false;
        }
        if (!($mime = $this->getHttpAssetMime($http_response_header))) {
            $this->error('No mime type sent with "%s"', $url);
            return false;
        }
        return compact('content', 'mime');
    }

    /**
     * Extract the content type header from the headers returned with the
     * file_get_contents http call
     *
     * @param array $headers the `$http_response_headers` created by `file_get_contents`
     * @return false|string the mime type, or false if not found
     */
    protected function getHttpAssetMime($headers)
    {
        foreach ($headers as $header) {
            $header = strtolower($header);
            if (strpos($header, 'content-type:') === 0) {
                return trim(substr($header, strlen('content-type:')));
            }
        }
        return false;    
    }

    /**
     * Check if a successfully fetched an asset is of a type that can be
     * embedded given the current options.
     *
     * @param array $asset the return value of fetchAsset
     * @return boolean
     */
    protected function assetIsEmbeddable(array $asset)
    {
        $embed_fonts = ($this->flags & self::EMBED_FONTS);
        $is_font = strpos($asset['mime'], 'font') !== false;
        if ($is_font && !$embed_fonts) {
            return false;
        }
        
        $embed_svg = ($this->flags & self::EMBED_SVG);
        $is_svg = strpos($asset['mime'], 'svg') !== false;
        if ($is_svg && !($embed_svg || $embed_fonts)) {
            return false;
        }
        
        return true;
    }

    /**
     * Check if an asset is remote or local
     *
     * @param string $path the path specified in the CSS file
     *
     * @return bool
     */
    protected function isHttpAsset($path)
    {
        if (!$this->http_enabled) {
            return false;
        }
        // if the root directory is remote, all assets are remote
        $schemes = array('http://', 'https://', '//');
        foreach ($schemes as $scheme) {
            if (strpos($this->root_dir, $scheme) === 0) {
                return true;
            }
        }
        // check for remote embedded assets
        $schemes[] = '/'; // absolutes should be remote
        foreach ($schemes as $scheme) {
            if (strpos($path, $scheme) === 0) {
                return true;
            }
        }
        // otherwise, it's a local asset
        return false;
    }

    /**
     * Resolve the absolute path to a local asset
     *
     * @param string $path the path to the asset, relative to root_dir
     * @return false|string the absolute path, or false if not found
     */
    protected function resolveAssetPath($path)
    {
        if (preg_match('/[:\?#]/', $path)) {
            return false;
        }
        return realpath($this->root_dir . DIRECTORY_SEPARATOR . $path);
    }

    /**
     * Resolve the URL to an http asset
     *
     * @param string $path
     * @return false|string the url, or false if not resolvable
     */
    protected function resolveAssetUrl($path)
    {
        $url = $this->buildAssetUrl($path);
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
        $this->error('Invalid asset url "%s"', $url);
        return false;
    }


    /**
     * Resolve the URL to an http asset
     *
     * @param string $path
     * @return false|string the url, or false if not resolvable
     */
    protected function buildAssetUrl($path)
    {
        $default_scheme = ($this->http_flags & self::HTTP_DEFAULT_HTTPS)
                        ? 'https:'
                        : 'http:'
                        ;

        // case 1: path is already fully qualified url
        if (strpos($path, '//') === 0) {
            $path = $default_scheme . $path;
        }
        if (preg_match('/^https?:\/\//', $path)) {
            return $path;
        }

        $root_url = $this->root_dir;
        if (strpos($root_url, '//') === 0) {
            $root_url = $default_scheme . $root_url;
        }
        $root_domain = preg_replace('#^(https?://[^/]+).*#', '$1', $root_url);
        $root_path = substr($root_url, strlen($root_domain));

        // case 2: asset is absolute path
        if (strpos($path, '/') === 0) {
            return $root_domain . $path;
        }

        // case 3: asset is relative path
        $path = $this->removePathTraversals($root_path . '/' . $path);
        $url = $root_domain . '/' . $path;
        return $url;
    }

    /**
     * Remove directory traversals from a path. Exists because file_get_contents
     * seems to choke on http://example.com/path/to/dir/../other-dir/file.txt
     *
     * @param string $path
     * @return string
     */
    protected function removePathTraversals($path)
    {
        $path = explode('/', $path);
        $return = array();
        foreach ($path as $part) {
            if ($part == '..') {
                array_pop($return);
            } elseif ($part && $part !== '.') {
                $return[] = $part;
            }
        }
        return implode('/', $return);
    }

    /**
     * Check the file mime type against the mime.types file
     *
     * @param string $path the path to the file
     * @return string the mime, or false if it could not be identified
     */
    protected function detectMime($path)
    {
        if (!$this->mime_magic_path) {
            return false;
        }
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (!$ext) {
            return false;
        }
        $mime_types = file($this->mime_magic_path);
        foreach ($mime_types as $line) {
            if ($mime = $this->compareMime($ext, $line)) {
                return $mime;
            }
        }
        return false;
    }

    /**
     * Compare an extention against the a line in the mime.types
     *
     * @param string $ext the file extension
     * @param string $line the line from the mime.types file
     * @return string|bool the mime type if there is a match, false if not
     */
    protected function compareMime($ext, $line)
    {
        if (strpos($line, '#') === 0) {
            return false;
        }
        $line = preg_replace('/\s+/', ' ', $line);
        $line = array_filter(explode(' ', $line));
        $mime = array_shift($line);
        return in_array($ext, $line) ? $mime : false;
    }

    /**
     * Download the Apache mimes.types file and save it locally
     *
     * @param string $path the path to save the file to
     * @return void
     */
    protected function createMimesFile($path)
    {
        $mime_types = @file_get_contents(self::MIME_MAGIC_URL);
        if ($mime_types === false) {
            return;
        }
        // special case: woff2 is too new
        if (strpos($mime_types, 'woff2') === false) {
            $mime_types .= "\napplication/font-woff2 woff2";
        }
        file_put_contents($path, $mime_types);
        clearstatcache();
    }

    /**
     * Throw an exception if URL_ON_ERROR is not set
     *
     * This method accepts an unlimited number of arguments. They will be passed
     * to sprintf to generate the error message in the exception.  For example:
     *
     *     $this->error('My exception about %d %s', 4, 'cats');
     *
     * would throw an exception with with the message "My error about 4 cats".
     *
     * @throws \InvalidArgmumentException
     * @return void
     */
    protected function error()
    {
        if ($this->flags & self::URL_ON_ERROR) {
            return;
        }
        $args = func_get_args();
        if (empty($args)) {
            $args[] = 'Unknown Error';
        }
        $msg = count($args) > 1
             ? call_user_func_array('sprintf', $args)
             : array_shift($args)
             ;
        throw new \InvalidArgumentException($msg);
    }
}
