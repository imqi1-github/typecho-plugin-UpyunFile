<?php

namespace UpyunFile;

use Typecho\Db\Exception;
use Typecho\Response;
use Typecho\Widget;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * UpyunFile 插件 Action 处理
 */
class Action extends Widget
{
    /**
     * 获取数据库表名
     */
    private static function getTableName(): string
    {
        $db = Options::alloc()->db;
        return $db->getPrefix() . 'upyunfile_config';
    }

    /**
     * 保存当前配置到数据库
     */
    public function saveConfig()
    {
        $options = Options::alloc();
        $settings = $options->plugin('UpyunFile');
        $db = $options->db;
        $name = $this->request->get('name');

        if (empty($name)) {
            Response::setStatus(400);
            echo json_encode(['success' => false, 'message' => _t('备份名称不能为空')]);
            exit;
        }

        $config = [
            'upyundomain' => $settings->upyundomain,
            'mode' => $settings->mode,
            'upyunhost' => $settings->upyunhost,
            'upyunuser' => $settings->upyunuser,
            'upyunpwd' => $settings->upyunpwd,
            'convert' => $settings->convert,
            'convertPic' => $settings->convertPic,
            'thumbId' => $settings->thumbId,
            'outputFormat' => $settings->outputFormat,
            'addToken' => $settings->addToken,
            'secret' => $settings->secret,
            'etime' => $settings->etime,
        ];

        $tableName = self::getTableName();
        $adapterName = $db->getAdapterName();

        try {
            if ($adapterName === 'Pdo_SQLite') {
                $db->query($db->insert($tableName)->rows([
                    'name' => $name,
                    'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s')
                ]));
            } else {
                $db->query($db->insert($tableName)->rows([
                    'name' => $name,
                    'config' => json_encode($config, JSON_UNESCAPED_UNICODE)
                ]));
            }
            echo json_encode(['success' => true, 'message' => _t('配置已保存')]);
        } catch (Exception $e) {
            Response::setStatus(500);
            echo json_encode(['success' => false, 'message' => _t('保存失败: ') . $e->getMessage()]);
        }
        exit;
    }

    /**
     * 从数据库恢复配置
     */
    public function restoreConfig()
    {
        $id = $this->request->get('id');

        if (empty($id)) {
            Response::setStatus(400);
            echo json_encode(['success' => false, 'message' => _t('配置 ID 不能为空')]);
            exit;
        }

        $db = Options::alloc()->db;
        $tableName = self::getTableName();

        try {
            $row = $db->fetchRow($db->select()->from($tableName)->where('id = ?', $id));

            if (!$row) {
                Response::setStatus(404);
                echo json_encode(['success' => false, 'message' => _t('配置不存在')]);
                exit;
            }

            $config = json_decode($row['config'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Response::setStatus(500);
                echo json_encode(['success' => false, 'message' => _t('配置数据损坏')]);
                exit;
            }

            // 更新插件配置
            $db->query($db->update('table.options')->rows(['value' => serialize($config)])
                ->where('name = ?', 'plugin:UpyunFile'));

            echo json_encode(['success' => true, 'message' => _t('配置已恢复')]);
        } catch (Exception $e) {
            Response::setStatus(500);
            echo json_encode(['success' => false, 'message' => _t('恢复失败: ') . $e->getMessage()]);
        }
        exit;
    }

    /**
     * 删除已保存的配置
     */
    public function deleteConfig()
    {
        $id = $this->request->get('id');

        if (empty($id)) {
            Response::setStatus(400);
            echo json_encode(['success' => false, 'message' => _t('配置 ID 不能为空')]);
            exit;
        }

        $db = Options::alloc()->db;
        $tableName = self::getTableName();

        try {
            $db->query($db->delete($tableName)->where('id = ?', $id));
            echo json_encode(['success' => true, 'message' => _t('配置已删除')]);
        } catch (Exception $e) {
            Response::setStatus(500);
            echo json_encode(['success' => false, 'message' => _t('删除失败: ') . $e->getMessage()]);
        }
        exit;
    }

    /**
     * 获取已保存的配置列表
     */
    public function listConfig()
    {
        $db = Options::alloc()->db;
        $tableName = self::getTableName();

        try {
            $rows = $db->fetchAll($db->select('id', 'name', 'created_at')
                ->from($tableName)
                ->order('created_at', 'DESC')
                ->limit(50));

            $list = [];
            foreach ($rows as $row) {
                $list[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'created_at' => $row['created_at']
                ];
            }

            echo json_encode(['success' => true, 'list' => $list]);
        } catch (Exception $e) {
            Response::setStatus(500);
            echo json_encode(['success' => false, 'message' => _t('获取列表失败: ') . $e->getMessage(), 'list' => []]);
        }
        exit;
    }
}
