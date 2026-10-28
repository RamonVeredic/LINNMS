<?php

declare(strict_types=1);
final class CanonicalJson {
    public static function encode(array $value): string {
        $value=self::normalize($value);
        return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
    }
    private static function normalize(mixed $value): mixed {
        if(!is_array($value)) return $value;
        if(array_is_list($value)) return array_map([self::class,'normalize'],$value);
        ksort($value,SORT_STRING);
        foreach($value as $k=>$v) $value[$k]=self::normalize($v);
        return $value;
    }
}
