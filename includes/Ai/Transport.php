<?php
namespace Meelano\Ai;

/**
 * لایه HTTP قابل تعویض.
 *
 * چرا رابط (interface)؟ تا در تست‌ها بتوان یک انتقال‌دهنده ساختگی تزریق کرد
 * و کد واقعی مسیریابی/تلاش مجدد/لاگ را بدون اینترنت اجرا کرد.
 */
interface Transport
{
    /**
     * @return array{status:int,body:string,headers:array,error:string,elapsed_ms:float}
     */
    public function request(string $method, string $url, array $options = []): array;
}
