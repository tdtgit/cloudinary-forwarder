<?php

class TDTCloudinaryForwarder
{
    private string $cloudName;
    private string $cloudMapping;

    private string $imgRequestURL;
    private string $imgRequestRegex = "/^(?:.*(?:\/wp-content\/uploads\/)(.*)-([0-9]{1,4})(?:x)([0-9]{1,4})).*\.(jpe?g|gif|png)$/";
    private string $imgRequestRegexOriginal = "/^(?:.*(?:\/wp-content\/uploads\/)(.*)).*\.(jpe?g|gif|png|mp4)$/";

    private string $imgName;
    private string $imgExtension;
    private array $imgSize;

    // Constructor
    public function __construct()
    {
        $this->cloudName = getenv('CLOUDINARY_CLOUD_NAME');
        $this->cloudMapping = getenv('CLOUDINARY_CLOUD_MAPPING');
        $this->cloudUploadEndpoint = 'https://res.cloudinary.com/' . $this->cloudName;

        $this->getRequestImg();
    }

    //  php get full request URL
    private function getRequestURL($is_fowarded = false)
    {
        $ssl      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on');
        $sp       = strtolower($_SERVER['SERVER_PROTOCOL']);
        $protocol = substr($sp, 0, strpos($sp, '/')) . (($ssl) ? 's' : '');
        $port     = $_SERVER['SERVER_PORT'];
        $port     = ((!$ssl && $port == '80') || ($ssl && $port == '443')) ? '' : ':' . $port;
        $http_host =isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null;
        $host     = ($is_fowarded && isset($_SERVER['HTTP_X_FORWARDED_HOST'])) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : $http_host;
        $host     = isset($host) ? $host : $_SERVER['SERVER_NAME'] . $port;
        return $protocol . '://' . $host . $_SERVER['REQUEST_URI'];
    }

    private function getCloudinaryURL()
    {
        // From /2022/01/cloudflare-chrome.png to tuandev-s3/2022/01/cloudflare-chrome.png
        $theURL = $this->cloudMapping . '/' . $this->imgName . '.' . $this->imgExtension;

        // From tuandev-s3/2022/01/cloudflare-chrome.png to f_auto/tuandev-s3/2022/01/cloudflare-chrome.png
        $theURL  = $this->getImageSizing() . $this->getImageFormatByAcceptHeader() . '/' . $theURL;

        // Add endpoint to the start
        $theURL = '/upload/' . $theURL;

        $theURL = $this->cloudUploadEndpoint . ($this->isVideoRequest() ? '/video' : '/image') . $theURL;

        return $theURL;
    }

    private function getImageSizing()
    {
        if ($this->isResizeRequest() === false){
            return '';
        }
        return 'c_scale,w_' . $this->imgSize['width'] . '/';
    }

    private function getImageFormatByAcceptHeader()
    {
        if (str_contains("mp4|webm|ogg|ogv|mp3|wav|flac|aac|m4a|m4v|mov|wmv|avi|mkv|mpg|mpeg|3gp|3g2|gif", $this->imgExtension)) {
            return 'f_auto,q_auto';
        }
        return $this->CloudinaryMapByAcceptHeader();
    }

    private function CloudinaryMapByAcceptHeader()
    {
        switch (true) {
            case stristr($_SERVER['HTTP_ACCEPT'], 'image/avif'):
                return 'f_avif,q_auto:best';
            case stristr($_SERVER['HTTP_ACCEPT'], 'image/webp'):
                return 'f_webp,q_auto:best';
            default:
                return 'f_auto,q_auto:best';
        }
    }

    private function getRequestImg()
    {
        $this->imgRequestURL = $this->getRequestURL();

        if (preg_match_all($this->imgRequestRegex, $this->imgRequestURL, $matches)) {
            $this->imgName = $matches[1][0];
            $this->imgSize = [
                'width' => $matches[2][0],
                'height' => $matches[3][0]
            ];
            $this->imgExtension = strtolower($matches[4][0]);
        } else {
            preg_match_all($this->imgRequestRegexOriginal, $this->imgRequestURL, $matches);
            $this->imgName = $matches[1][0];
            $this->imgSize = [];
            $this->imgExtension = strtolower($matches[2][0]);
        }
    }

    public function saveImg()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getCloudinaryURL());
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.1 Safari/537.11');
        $response = curl_exec($ch);

        $headers = $this->getResponseHeader($response);
        header("Content-Type: " . $headers['content-type']);
        header("Content-Length: " . $headers['content-length']);
        header("x-content-type-options: nosniff");
        echo explode("\r\n\r\n", $response, 2)[1];
    }

    private function getResponseHeader($response)
    {
        $headers = array();

        $header_text = substr($response, 0, strpos($response, "\r\n\r\n"));

        foreach (explode("\r\n", $header_text) as $i => $line) {
            if ($i === 0) {
                $headers['http_code'] = $line;
            } else {
                list($key, $value) = explode(': ', $line);

                $headers[$key] = $value;
            }
        }
        return $headers;
    }

    // check $this->imgSize is not empty
    private function isResizeRequest()
    {
        return !empty($this->imgSize);
    }

    private function isVideoRequest()
    {
        if (in_array($this->imgExtension, ['mp4', 'webm', 'ogg', 'ogv', 'mp3', 'wav', 'flac', 'aac', 'm4a', 'm4v', 'mov', 'wmv', 'avi', 'mkv', 'mpg', 'mpeg', '3gp', '3g2', 'gif'])) {
            return true;
        }
    }
}

$newWPImgCloudinary = new TDTCloudinaryForwarder();
$newWPImgCloudinary->saveImg();
