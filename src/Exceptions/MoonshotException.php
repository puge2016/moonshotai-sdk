<?php
namespace Puge2016\MoonshotAiSdk\Exceptions;

use Exception;

class MoonshotException extends Exception
{
    /**
     * 错误类型标识
     * @var string
     */
    protected string $errorType;

    /**
     * 原始错误响应数据
     * @var array|null
     */
    protected ?array $responseData;

    /**
     * 错误发生位置
     * @var array|null
     */
    protected ?array $errorLocation;

    /**
     * 创建一个新的 MoonshotException 实例
     *
     * @param string $message 错误消息
     * @param int $code HTTP 状态码
     * @param string $errorType 错误类型标识
     * @param array|null $responseData 原始错误响应数据
     * @param \Throwable|null $previous 上一个异常
     */
    public function __construct(
        string $message, 
        int $code = 0, 
        string $errorType = 'general_error',
        ?array $responseData = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errorType = $errorType;
        $this->responseData = $responseData;
        $this->errorLocation = $this->captureErrorLocation();
    }

    /**
     * 获取错误类型标识
     *
     * @return string
     */
    public function getErrorType(): string
    {
        return $this->errorType;
    }

    /**
     * 获取原始错误响应数据
     *
     * @return array|null
     */
    public function getResponseData(): ?array
    {
        return $this->responseData;
    }

    /**
     * 捕获错误发生的位置信息
     * 
     * @return array
     */
    protected function captureErrorLocation(): array
    {
        $trace = $this->getTrace();
        
        // 获取第一个非异常相关的调用位置，通常是实际发生错误的代码位置
        foreach ($trace as $item) {
            // 排除异常处理相关的类
            if (!isset($item['class']) || 
                strpos($item['class'], 'Puge2016\\MoonshotAiSdk\\Exceptions\\') !== 0) {
                return [
                    'file' => $item['file'] ?? '未知文件',
                    'line' => $item['line'] ?? 0,
                    'function' => ($item['class'] ?? '') . 
                                 ($item['type'] ?? '') . 
                                 ($item['function'] ?? '未知函数')
                ];
            }
        }
        
        // 如果没有找到合适的调用位置，使用异常创建的位置
        return [
            'file' => $this->getFile() ?? '未知文件',
            'line' => $this->getLine() ?? 0,
            'function' => '未知函数'
        ];
    }

    /**
     * 获取错误发生位置信息
     * 
     * @return array
     */
    public function getErrorLocation(): array
    {
        return $this->errorLocation;
    }

    /**
     * 获取格式化的错误位置信息
     * 
     * @return string
     */
    public function getFormattedErrorLocation(): string
    {
        $location = $this->errorLocation;
        return sprintf(
            "在文件 %s 的第 %d 行（%s）", 
            $location['file'], 
            $location['line'], 
            $location['function']
        );
    }
    
    /**
     * 获取详细的错误栈信息，限制返回的层数
     * 
     * @param int $depth 要返回的调用栈深度，默认为3
     * @return string
     */
    public function getDetailedStackInfo(int $depth = 3): string
    {
        $trace = $this->getTrace();
        $stackInfo = "错误详细栈信息：\n";
        
        $count = 0;
        foreach ($trace as $i => $item) {
            if ($count >= $depth) break;
            
            // 排除异常处理相关的类
            if (!isset($item['class']) || 
                strpos($item['class'], 'Puge2016\\MoonshotAiSdk\\Exceptions\\') !== 0) {
                $stackInfo .= sprintf(
                    "#%d %s(%d): %s%s%s()\n",
                    $count,
                    $item['file'] ?? '未知文件',
                    $item['line'] ?? 0,
                    $item['class'] ?? '',
                    $item['type'] ?? '',
                    $item['function'] ?? '未知函数'
                );
                $count++;
            }
        }
        
        return $stackInfo;
    }

    /**
     * 判断是否为认证错误（401）
     *
     * @return bool
     */
    public function isAuthError(): bool
    {
        return $this->code === 401;
    }

    /**
     * 判断是否为请求速率限制错误（429）
     *
     * @return bool
     */
    public function isRateLimitError(): bool
    {
        return $this->code === 429;
    }

    /**
     * 判断是否为参数验证错误（400）
     *
     * @return bool
     */
    public function isValidationError(): bool
    {
        return $this->code === 400;
    }

    /**
     * 判断是否为服务器错误（500系列）
     *
     * @return bool
     */
    public function isServerError(): bool
    {
        return $this->code >= 500 && $this->code < 600;
    }

    /**
     * 判断是否为内容过滤错误
     *
     * @return bool
     */
    public function isContentFilterError(): bool
    {
        return $this->errorType === 'content_filter';
    }

    /**
     * 判断是否为上下文长度限制错误
     *
     * @return bool
     */
    public function isContextLengthError(): bool
    {
        return $this->errorType === 'context_length';
    }

    /**
     * 判断是否为账户未激活错误
     *
     * @return bool
     */
    public function isAccountInactiveError(): bool
    {
        return $this->errorType === 'account_inactive_error';
    }

    /**
     * 获取格式化的错误信息，包含错误类型和代码
     *
     * @return string
     */
    public function getFormattedMessage(): string
    {
        return sprintf(
            "[%s][%d] %s", 
            $this->errorType, 
            $this->code, 
            $this->message
        );
    }
    
    /**
     * 获取完整的错误信息，包括错误类型、代码、消息和位置
     * 
     * @return string
     */
    public function getFullErrorInfo(): string
    {
        return sprintf(
            "%s\n%s", 
            $this->getFormattedMessage(), 
            $this->getFormattedErrorLocation()
        );
    }
} 