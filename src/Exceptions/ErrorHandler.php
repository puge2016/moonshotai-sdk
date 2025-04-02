<?php
namespace Puge2016\MoonshotAiSdk\Exceptions;

use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * MoonshotAI SDK 错误处理器
 * 处理各种 API 请求过程中的错误，并将其转换为统一的 MoonshotException
 */
class ErrorHandler
{
    /**
     * 处理 API 请求错误
     *
     * @param GuzzleException $exception 原始异常
     * @return MoonshotException 统一处理后的异常
     */
    public static function handleApiError(GuzzleException $exception): MoonshotException
    {
        if ($exception instanceof ClientException) {
            return self::handleClientError($exception);
        }
        
        if ($exception instanceof ServerException) {
            return self::handleServerError($exception);
        }
        
        if ($exception instanceof ConnectException) {
            return new MoonshotException(
                "连接 MoonshotAI API 服务器失败: " . $exception->getMessage(),
                0,
                'connection_error'
            );
        }
        
        if ($exception instanceof TooManyRedirectsException) {
            return new MoonshotException(
                "请求重定向次数过多: " . $exception->getMessage(),
                0,
                'redirect_error'
            );
        }
        
        if ($exception instanceof RequestException) {
            return new MoonshotException(
                "请求错误: " . $exception->getMessage(),
                0,
                'request_error'
            );
        }
        
        // 其他类型的异常
        return new MoonshotException(
            "API 请求错误: " . $exception->getMessage(),
            0,
            'api_error'
        );
    }
    
    /**
     * 处理客户端错误（4xx 错误）
     *
     * @param ClientException $exception 客户端错误异常
     * @return MoonshotException 统一处理后的异常
     */
    private static function handleClientError(ClientException $exception): MoonshotException
    {
        $response = $exception->getResponse();
        $statusCode = $response->getStatusCode();
        
        // 尝试解析响应 JSON
        $responseData = self::parseResponseJson($response);
        $errorMessage = $responseData['error']['message'] ?? $exception->getMessage();
        
        switch ($statusCode) {
            case 400:
                return self::handle400Error($errorMessage, $responseData, $exception);
                
            case 401:
                return new MoonshotException(
                    "API 密钥无效或已过期，请检查 API Key 是否正确设置",
                    401,
                    'authentication_error',
                    $responseData,
                    $exception  // 传递原始异常
                );
                
            case 403:
                // 检查是否为账户未激活的错误
                if (stripos($errorMessage, 'not active') !== false || 
                    stripos($errorMessage, '未激活') !== false || 
                    stripos($errorMessage, '未开通') !== false) {
                    return new MoonshotException(
                        "账户未激活或已被禁用，请联系管理员: " . $errorMessage,
                        403,
                        'account_inactive_error',
                        $responseData,
                        $exception  // 传递原始异常
                    );
                }
                
                return new MoonshotException(
                    "API 请求被拒绝，您没有权限执行此操作: " . $errorMessage,
                    403,
                    'permission_error',
                    $responseData,
                    $exception  // 传递原始异常
                );
                
            case 404:
                return new MoonshotException(
                    "请求的资源不存在: " . $errorMessage,
                    404,
                    'not_found_error',
                    $responseData,
                    $exception  // 传递原始异常
                );
                
            case 429:
                $retryAfter = $response->getHeaderLine('Retry-After');
                $waitTime = $retryAfter ? (int)$retryAfter : 5;
                return new MoonshotException(
                    "请求频率超限，建议{$waitTime}秒后重试",
                    429,
                    'rate_limit_error',
                    $responseData,
                    $exception  // 传递原始异常
                );
                
            default:
                return new MoonshotException(
                    "客户端错误 ({$statusCode}): " . $errorMessage,
                    $statusCode,
                    'client_error',
                    $responseData,
                    $exception  // 传递原始异常
                );
        }
    }
    
    /**
     * 处理服务器错误（5xx 错误）
     *
     * @param ServerException $exception 服务器错误异常
     * @return MoonshotException 统一处理后的异常
     */
    private static function handleServerError(ServerException $exception): MoonshotException
    {
        $response = $exception->getResponse();
        $statusCode = $response->getStatusCode();
        
        // 尝试解析响应 JSON
        $responseData = self::parseResponseJson($response);
        $errorMessage = $responseData['error']['message'] ?? $exception->getMessage();
        
        return new MoonshotException(
            "服务器错误 ({$statusCode}): " . $errorMessage,
            $statusCode,
            'server_error',
            $responseData
        );
    }
    
    /**
     * 处理 400 错误的具体类型
     *
     * @param string $errorMessage 错误消息
     * @param array|null $responseData 响应数据
     * @param \Throwable|null $previous 上一个异常
     * @return MoonshotException 统一处理后的异常
     */
    private static function handle400Error(string $errorMessage, ?array $responseData, ?\Throwable $previous = null): MoonshotException
    {
        // 处理上下文长度错误
        if (stripos($errorMessage, 'context length') !== false ||
            stripos($errorMessage, 'token limit') !== false ||
            stripos($errorMessage, 'too many tokens') !== false ||
            stripos($errorMessage, '超过令牌限制') !== false ||
            stripos($errorMessage, '超出最大上下文长度') !== false) {
            return new MoonshotException(
                "输入内容超出模型上下文窗口大小限制: " . $errorMessage,
                400,
                'context_length',
                $responseData,
                $previous
            );
        }
        
        // 处理内容过滤错误
        if (stripos($errorMessage, 'content filter') !== false ||
            stripos($errorMessage, 'filtered') !== false ||
            stripos($errorMessage, 'moderation') !== false ||
            stripos($errorMessage, '内容被过滤') !== false ||
            stripos($errorMessage, '违反内容政策') !== false) {
            return new MoonshotException(
                "输入或输出内容被内容安全过滤，包含敏感信息: " . $errorMessage,
                400,
                'content_filter',
                $responseData,
                $previous
            );
        }
        
        // 处理参数无效错误
        if (stripos($errorMessage, 'invalid') !== false ||
            stripos($errorMessage, '无效') !== false ||
            stripos($errorMessage, '参数错误') !== false) {
            return new MoonshotException(
                "请求参数无效: " . $errorMessage,
                400,
                'invalid_parameter',
                $responseData,
                $previous
            );
        }
        
        // 处理余额不足错误
        if (stripos($errorMessage, 'insufficient') !== false && 
            (stripos($errorMessage, 'balance') !== false || stripos($errorMessage, 'credit') !== false) ||
            stripos($errorMessage, '余额不足') !== false) {
            return new MoonshotException(
                "账户余额不足，请充值后再试: " . $errorMessage,
                400,
                'insufficient_balance',
                $responseData,
                $previous
            );
        }
        
        // 处理文件相关错误
        if (stripos($errorMessage, 'file') !== false &&
            (stripos($errorMessage, 'not found') !== false || 
             stripos($errorMessage, 'invalid') !== false ||
             stripos($errorMessage, 'too large') !== false)) {
            return new MoonshotException(
                "文件处理错误: " . $errorMessage,
                400,
                'file_error',
                $responseData,
                $previous
            );
        }
        
        // 默认的 400 错误
        return new MoonshotException(
            "请求参数错误: " . $errorMessage,
            400,
            'bad_request',
            $responseData,
            $previous
        );
    }
    
    /**
     * 解析响应 JSON 数据
     *
     * @param ResponseInterface $response 响应对象
     * @return array|null 解析后的 JSON 数据，解析失败返回 null
     */
    private static function parseResponseJson(ResponseInterface $response): ?array
    {
        try {
            $contents = $response->getBody()->getContents();
            if (empty($contents)) {
                return null;
            }
            
            $result = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            
            // 尝试从响应中找出真正的错误信息
            if (isset($result['error'])) {
                // 标准格式
                return $result;
            } else if (isset($result['message']) && isset($result['code'])) {
                // 自定义的错误格式
                return [
                    'error' => [
                        'message' => $result['message'],
                        'code' => $result['code'],
                        'type' => $result['type'] ?? 'error'
                    ]
                ];
            }
            
            return $result;
        } catch (JsonException $e) {
            // 如果不是JSON格式，返回原始内容作为错误消息
            return [
                'error' => [
                    'message' => "非JSON响应: " . substr($response->getBody(), 0, 100) . '...',
                    'type' => 'invalid_json'
                ]
            ];
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * 创建统一的错误异常
     *
     * @param string $message 错误消息
     * @param int $code HTTP 状态码
     * @param string $errorType 错误类型标识
     * @param array|null $responseData 原始响应数据
     * @param \Throwable|null $previous 上一个异常
     * @return MoonshotException
     */
    public static function createError(
        string $message,
        int $code = 0,
        string $errorType = 'general_error',
        ?array $responseData = null,
        ?\Throwable $previous = null
    ): MoonshotException
    {
        return new MoonshotException(
            $message,
            $code,
            $errorType,
            $responseData,
            $previous  // 传递原始异常以保留堆栈信息
        );
    }
} 