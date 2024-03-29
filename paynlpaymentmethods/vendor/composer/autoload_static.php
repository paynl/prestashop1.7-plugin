<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInita3a46c0e362da920ffeb331fe601b182
{
    public static $prefixLengthsPsr4 = array (
        'P' => 
        array (
            'Paynl\\' => 6,
            'PaynlPaymentMethods\\PrestaShop\\' => 31,
        ),
        'C' => 
        array (
            'Curl\\' => 5,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Paynl\\' => 
        array (
            0 => __DIR__ . '/..' . '/paynl/sdk/src',
        ),
        'PaynlPaymentMethods\\PrestaShop\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
        'Curl\\' => 
        array (
            0 => __DIR__ . '/..' . '/php-curl-class/php-curl-class/src/Curl',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInita3a46c0e362da920ffeb331fe601b182::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInita3a46c0e362da920ffeb331fe601b182::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInita3a46c0e362da920ffeb331fe601b182::$classMap;

        }, null, ClassLoader::class);
    }
}
