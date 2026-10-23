<?php
/**
 * CaseNamePreset - admin-managed list of case names
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * Doctors choose a case name from this list; only an admin adds, renames or
 * removes entries (enforced in api/case-names.php). cases.case_name keeps the
 * chosen text, so editing the list never rewrites existing cases.
 */

require_once __DIR__ . '/Database.php';

class CaseNamePreset {

    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function all() {
        return $this->db->fetchAll(
            "SELECT id, name, scope_type FROM case_name_presets ORDER BY sort_order, id"
        );
    }

    /** 'tooth' | 'arch' — anything else falls back to 'tooth'. */
    public static function scope($scope) {
        return $scope === 'arch' ? 'arch' : 'tooth';
    }

    /** True when $name is one of the listed names — the create_case guard. */
    public function exists($name) {
        $name = trim((string)$name);
        if ($name === '') {
            return false;
        }
        return (bool)$this->db->fetch(
            "SELECT id FROM case_name_presets WHERE name = :name",
            ['name' => $name]
        );
    }

    public function add($name, $scope = 'tooth', $userId = null) {
        $scope = self::scope($scope);
        $name = $this->clean($name);
        if ($name === '') {
            return ['success' => false, 'message' => 'ناوی کەیس داواکراوە'];
        }
        if ($this->nameTaken($name)) {
            return ['success' => false, 'message' => 'ئەم ناوە پێشتر زیادکراوە'];
        }
        $max = $this->db->fetch("SELECT MAX(sort_order) AS m FROM case_name_presets");
        $id = $this->db->insert('case_name_presets', [
            'name'       => $name,
            'scope_type' => $scope,
            'sort_order' => (int)($max['m'] ?? 0) + 1,
            'created_by' => $userId ? (int)$userId : null,
        ]);
        return ['success' => true, 'id' => (int)$id, 'name' => $name, 'scope_type' => $scope];
    }

    public function update($id, $name, $scope = 'tooth') {
        $id = (int)$id;
        $scope = self::scope($scope);
        $name = $this->clean($name);
        if ($name === '') {
            return ['success' => false, 'message' => 'ناوی کەیس داواکراوە'];
        }
        if (!$this->db->fetch("SELECT id FROM case_name_presets WHERE id = :id", ['id' => $id])) {
            return ['success' => false, 'message' => 'ناوەکە نەدۆزرایەوە'];
        }
        if ($this->nameTaken($name, $id)) {
            return ['success' => false, 'message' => 'ئەم ناوە پێشتر زیادکراوە'];
        }
        $this->db->update('case_name_presets', ['name' => $name, 'scope_type' => $scope], 'id = :id', ['id' => $id]);
        return ['success' => true, 'id' => $id, 'name' => $name, 'scope_type' => $scope];
    }

    public function remove($id) {
        $this->db->delete('case_name_presets', 'id = :id', ['id' => (int)$id]);
        return ['success' => true, 'id' => (int)$id];
    }

    private function clean($name) {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$name)), 0, 255);
    }

    private function nameTaken($name, $exceptId = 0) {
        return (bool)$this->db->fetch(
            "SELECT id FROM case_name_presets WHERE name = :name AND id <> :id",
            ['name' => $name, 'id' => (int)$exceptId]
        );
    }
}
