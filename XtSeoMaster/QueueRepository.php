<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * XtSeoMaster 推送日志仓储。
 *
 * 职责：
 * - 安装推送日志表
 * - 写入推送结果日志
 * - 查询近期推送记录
 * - 按 URL 删除对应推送记录
 */
class XtSeoMaster_QueueRepository
{
    private $db;
    private $logTable;

    public function __construct()
    {
        $this->db = Typecho_Db::get();
        $prefix = method_exists($this->db, 'getPrefix') ? $this->db->getPrefix() : 'typecho_';
        $this->logTable = $prefix . 'xtseomaster_push_log';
    }

    public function installTables()
    {
        $sqlLog = "CREATE TABLE IF NOT EXISTS `{$this->logTable}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `queue_id` BIGINT NOT NULL DEFAULT 0,
            `engine` VARCHAR(32) NOT NULL,
            `url` VARCHAR(1024) NOT NULL,
            `request_payload` TEXT NULL,
            `response_code` INT NOT NULL DEFAULT 0,
            `response_body` MEDIUMTEXT NULL,
            `is_success` TINYINT(1) NOT NULL DEFAULT 0,
            `error_message` TEXT NULL,
            `created_at` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_engine_time` (`engine`, `created_at`),
            KEY `idx_queue_id` (`queue_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        try {
            $this->db->query($sqlLog, Typecho_Db::WRITE);
        } catch (Exception $e) {
            // 安装阶段不阻断启用，交由日志与后台提示处理
        }
    }

    public function logPushResult($queueId, $engine, $url, $requestPayload, $responseCode, $responseBody, $isSuccess, $errorMessage)
    {
        $this->db->query(
            $this->db->insert($this->logTable)
                ->rows(array(
                    'queue_id' => intval($queueId),
                    'engine' => $engine,
                    'url' => $url,
                    'request_payload' => mb_substr((string) $requestPayload, 0, 4000, 'UTF-8'),
                    'response_code' => intval($responseCode),
                    'response_body' => mb_substr((string) $responseBody, 0, 8000, 'UTF-8'),
                    'is_success' => $isSuccess ? 1 : 0,
                    'error_message' => mb_substr((string) $errorMessage, 0, 2000, 'UTF-8'),
                    'created_at' => time()
                )),
            Typecho_Db::WRITE
        );
    }

    public function recentLogs($limit)
    {
        return $this->db->fetchAll(
            $this->db->select()
                ->from($this->logTable)
                ->order('id', Typecho_Db::SORT_DESC)
                ->limit(intval($limit))
        );
    }

    public function deleteRecordsByUrls($urls)
    {
        $urls = array_values(array_unique(array_filter(array_map('trim', (array) $urls))));
        if (empty($urls)) {
            return array('deleted_logs' => 0);
        }

        $deletedLogs = 0;
        foreach ($urls as $url) {
            $logCountRow = $this->db->fetchRow(
                $this->db->select(array('COUNT(*)' => 'c'))
                    ->from($this->logTable)
                    ->where('url = ?', $url)
            );
            $deletedLogs += isset($logCountRow['c']) ? intval($logCountRow['c']) : 0;
            $this->db->query(
                $this->db->delete($this->logTable)
                    ->where('url = ?', $url),
                Typecho_Db::WRITE
            );
        }

        return array('deleted_logs' => $deletedLogs);
    }
}
