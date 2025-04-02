# MoonshotAI SDK 目录结构

扩展包的目录结构如下：

```
moonshotai-sdk/
├── composer.json        # Composer 配置文件
├── LICENSE              # MIT 许可证
├── README.md            # 使用说明文档
├── STRUCTURE.md         # 本文件，说明目录结构
│
├── examples/            # 示例代码
│   ├── basic-usage.php  # 基本使用示例
│   ├── file-processing.php  # 文件处理示例
│   └── example-files/   # 示例文件目录
│
└── src/                 # 源代码目录
    └── MoonshotAI.php   # 主类文件
```

## 如何集成到项目中

### 方法 1：通过 Composer 安装（推荐）

1. 将包发布到 Packagist
2. 在项目中运行 `composer require puge2016/moonshotai-sdk`

### 方法 2：手动集成

1. 复制 `src` 目录到您的项目中
2. 确保设置正确的自动加载或手动引入 `MoonshotAI.php` 文件

## 示例代码使用说明

示例代码位于 `examples` 目录中，展示了 SDK 的基本用法：

- `basic-usage.php`: 演示基本对话功能
- `file-processing.php`: 演示文件上传和处理功能

要运行示例，请先确保：

1. 替换示例中的 API 密钥为您自己的密钥
2. 对于文件处理示例，需要在 `examples/example-files` 目录中放置测试文件

## 开发指南

如果您想为这个 SDK 做贡献或扩展功能：

1. 主要功能都封装在 `src/MoonshotAI.php` 中
2. 添加新功能时请务必添加适当的文档注释
3. 扩展功能后更新 README.md 并添加相应的示例代码 