# 贡献指南
**_最后修改于 2025 年 11 月 12 日_**

首先，感谢你愿意为 mailcow 社区提供错误修复或新功能——正是因为你的帮助，项目才能持续成长！

为了让 mailcow 的开发保持有序，我们制定了以下指南，帮助你相应地创建 issue/pull request。

**请注意：如果不符合本文档中写明的指南，我们将关闭对应的 issue/pull request**。因此在提交 issue/pull request 之前，请先查阅本指南。

## 目录

- [Pull Request](#pull-request)
- [问题报告](#问题报告)
    - [指南](#问题报告指南)
    - [问题报告步骤](#问题报告步骤)

## Pull Request
**_最后修改于 2024 年 8 月 15 日_**

关于 pull request，请注意以下事项：

1. **务必**基于你本地克隆的 mailcow 实例的 staging 分支创建 PR，因为 PR 通过审核后会被合并到 mailcow 的该 staging 分支。理想情况下，你应当为 PR 新建一个分支，分支名由 PR 类型（例如功能更新用 `feat/`、错误修复用 `fix/`）和实际内容（例如从 SOGo 升级到 6 版本用 `sogo-6.0.0`，对 mailcow 中的 HTML 转义修复用 `html-escape`）组成。
2. **务必**使用英文报告问题或提出功能请求，尽管 mailcow 是一家德国公司。这样做是为了让不懂德语或其他语言的 GitHub 用户也能回复你的 issue/请求。
3. 请**保持**该 pull request 分支**干净**，不要包含与你所做更改无关的提交（例如来自其他分支的其他用户的提交）。*如果你修改了 `update.sh` 脚本或其他会触发提交的脚本，这种情况下通常有一个开发者模式可以保持工作区干净。*
4. **在将更改作为 pull request 提交之前先进行测试。**<ins>如果可能</ins>，请写一份简短的**测试日志**，或用**截图或 GIF** 演示功能。*我们当然也会自行测试你的 pull request，但你提供的证据可以省去我们追问你是否亲自测试过自己更改的环节。*
5. 创建 pull request 时，请**使用**我们提供的 pull request 模板。*提示：编辑过程中你会看到形如 `<!-- CONTENT -->` 的注释。它们可以被删除或保留，因为之后在 GitHub 上不会渲染出来！请只填写实际内容，不要包含上述注释。*
6. 请**务必**针对 staging 分支创建实际的 pull request，**切勿**直接针对 master 分支。*如果你忘记了，我们的 moobot 会提醒你切换分支到 staging。*
7. 等待合并提交：可能出于各种原因我们不会立即接受，甚至有时完全不接受你的 pull request。如果出现这种情况，请不要失望。我们始终致力于把社区中有意义的更改纳入 mailcow 项目。
8. 如果你计划提交较大、因而也更复杂的 pull request，建议先在单独的 issue 中说明，待想法被接受后再开始实现，以避免不必要的挫败与返工！
9. 如果你的 PR 需要重建 Docker 镜像（修改了 Dockerfile 或 data/Dockerfiles/ 中的文件），请更新 docker-compose.yml 中的镜像标签。请遵循基础镜像的版本号规则（例如版本升级用 ghcr.io/mailcow/sogo:5.12.4 → :5.12.5；补丁修复则在末尾追加字母，例如 :5.12.4a）。请遵循此规则。

---

## 问题报告
**_最后修改于 2025 年 11 月 12 日_**

如果你打算报告 mailcow 中的问题，请先阅读并理解以下规则：

### 安全披露 / 安全相关修复
- 安全漏洞与安全修复在被整合、发布或在 issue/PR 中公开之前，**务必**先保密地报告到 SECURITY.md 中指定的联系地址。请等待指定联系人的回复，以确保协调且负责任的披露流程。

### 问题报告指南

1. **仅**将 issue tracker 用于错误报告或改进请求，**不要**用于支持类提问。支持类提问可以联系 [Telegram 上的 mailcow 社区](https://docs.mailcow.email/#community-support-and-chat)，或通过[付费支持](https://docs.mailcow.email/#commercial-support)直接联系 mailcow 团队。
2. **仅**在你具备邮件服务器管理与 Docker 使用的**必要知识（至少是基础）**时才报告错误。mailcow 是一套复杂且功能完整的邮件服务器，在 Docker 基础上还包含群件组件，调试与运维需要一定的技术功底。
3. **务必**使用英文报告问题或提出功能请求，尽管 mailcow 是一家德国公司。这样做是为了让不懂德语或其他语言的 GitHub 用户也能回复你的 issue/请求。
4. **仅**报告存在于最新 mailcow 发行系列中的错误。*最新发行系列的定义包括最近一个主补丁（例如 2023-12）及其下方所有次补丁（修订版，例如 2023-12a、b、c 等）。* 自 2024 年 1 月 1 日起发布的新问题报告必须满足此标准，因为低于最新发行的版本我们已不再支持。
5. 报告问题时，请尽可能详细，并包含你对 mailcow 安装所做的每一处细微改动。请详细、准确地填写对应的错误报告表单，以减少可能的追问。
6. **在创建 issue/功能请求之前**，请先检查 mailcow 在 GitHub 上的 tracker 中是否已存在类似请求。如果已有，请在该请求中附上你的信息。
7. 当你创建 issue/功能请求时：请注意，创建并<ins>**不保证 mailcow 团队或社区会立即实现或修复**</ins>。
8. 提交错误报告或功能请求之前，请**务必**对其中任何敏感信息做匿名化处理。

### 问题报告步骤
1. 阅读你的日志；顺着日志查明问题的原因。
2. 顺着日志文件中给出的线索开始排查。
3. 重启出问题的服务或整个栈，看问题是否仍然存在。
4. 阅读出问题服务的[文档](https://docs.mailcow.email/)，并在其 bugtracker 中搜索你的问题。
5. 在我们的 [issues](https://github.com/mailcow/mailcow-dockerized/issues) 中搜索你的问题。
6. 如果你认为你的问题可能是 bug 或你急需的缺失功能，请在我们的 GitHub 仓库中[创建 issue](https://github.com/mailcow/mailcow-dockerized/issues/new/choose)。但请确保附上**所有日志**以及对问题的完整描述。
7. 在社区驱动的[支持渠道](https://docs.mailcow.email/#community-support-and-chat)中提出你的问题。

## 在创建 issue/功能请求或 pull request 时，系统会要求你确认这些指南。
