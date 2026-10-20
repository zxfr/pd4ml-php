<?php

declare(strict_types=1);

namespace Pd4ml;

/**
 * The public PD4ML Java classes (package `com.pd4ml`), and any other Java class by name.
 *
 *     Java::PageSize()->A4
 *     Java::PageMargins()->new(10, 10, 10, 10, Java::Units()->MM)
 *     Java::PdfSpec()->PDFA_3B
 *     Java::Constants()->AllowPrint
 *     Java::cls('java.util.Locale')->getDefault()
 */
final class Java
{
    public static function cls(string $name): JavaClass
    {
        return Bridge::get()->javaClass($name);
    }

    /** A Java object implementing the interface `$interface` that calls `$callback` for its methods. */
    public static function proxy(string $interface, callable $callback): JavaObject
    {
        return Bridge::get()->proxy($interface, $callback);
    }

    /** `com.pd4ml.PD4ML` itself (the unwrapped Java class; see {@see PD4ML}). */
    public static function PD4ML(): JavaClass
    {
        return self::cls('com.pd4ml.PD4ML');
    }

    public static function Constants(): JavaClass
    {
        return self::cls('com.pd4ml.Constants');
    }

    /** `Constants.LayoutEngine`: `v40`, `v42` (PD4ML 4.2 and later). */
    public static function LayoutEngine(): JavaClass
    {
        return self::cls('com.pd4ml.Constants$LayoutEngine');
    }

    /** `Constants.ErrorPolicy`: `NONE`, `RELAXED`, `NORMAL`, `PEDANTIC`, `STRICT`. */
    public static function ErrorPolicy(): JavaClass
    {
        return self::cls('com.pd4ml.Constants$ErrorPolicy');
    }

    public static function PageSize(): JavaClass
    {
        return self::cls('com.pd4ml.PageSize');
    }

    public static function PageMargins(): JavaClass
    {
        return self::cls('com.pd4ml.PageMargins');
    }

    public static function Dimensions(): JavaClass
    {
        return self::cls('com.pd4ml.Dimensions');
    }

    /** `Dimensions.Units`: `MM`, `PT`, `PX`. */
    public static function Units(): JavaClass
    {
        return self::cls('com.pd4ml.Dimensions$Units');
    }

    public static function PdfSpec(): JavaClass
    {
        return self::cls('com.pd4ml.PdfSpec');
    }

    public static function PdfDocument(): JavaClass
    {
        return self::cls('com.pd4ml.PdfDocument');
    }

    public static function ProgressListener(): JavaClass
    {
        return self::cls('com.pd4ml.ProgressListener');
    }

    public static function StatusMessage(): JavaClass
    {
        return self::cls('com.pd4ml.StatusMessage');
    }

    public static function ResourceProvider(): JavaClass
    {
        return self::cls('com.pd4ml.ResourceProvider');
    }

    public static function CustomTag(): JavaClass
    {
        return self::cls('com.pd4ml.CustomTag');
    }
}
