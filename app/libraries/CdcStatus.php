<?php

class CdcStatus
{
    public const REQUIRED_CDC_NAMES = [
        'Cacawan Centro Pamana CDC',
        'Marfrancisco CDC',
        'Papandayan Highway CDC',
    ];

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function is_required_name($name)
    {
        return $this->canonical_name($name) !== null;
    }

    public function canonical_name($name)
    {
        $normalized = $this->normalize_name($name);
        foreach (self::REQUIRED_CDC_NAMES as $required_name) {
            if ($normalized === $this->normalize_name($required_name)) {
                return $required_name;
            }
        }

        return null;
    }

    public function sync($center_id)
    {
        $center = $this->db->raw(
            'SELECT d.status,
                    EXISTS (
                        SELECT 1
                        FROM teacher_profiles tp
                        JOIN users u ON u.id = tp.user_id
                        JOIN notifications review ON review.id = (
                            SELECT latest.id FROM notifications latest
                            WHERE latest.related_type = \'teacher_verification\'
                              AND latest.related_id = u.id
                            ORDER BY latest.id DESC LIMIT 1
                        )
                        WHERE tp.cdc_id = d.id
                          AND tp.employment_status = \'active\'
                          AND u.role = \'teacher\'
                          AND u.is_active = 1
                          AND review.title = \'Approved\'
                    ) AS has_approved_teacher
             FROM cdcs d WHERE d.id = ? LIMIT 1',
            [(int) $center_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$center) {
            return false;
        }

        $status = (int) $center['has_approved_teacher'] === 1 ? 'active' : 'inactive';
        if ($center['status'] !== $status) {
            $this->db->table('cdcs')
                ->where('id', (int) $center_id)
                ->update([
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        }

        return $status;
    }

    public function sync_teacher_center($teacher_id)
    {
        $profile = $this->db->table('teacher_profiles')
            ->select('cdc_id')
            ->where('user_id', (int) $teacher_id)
            ->get();
        if (!$profile || $profile['cdc_id'] === null) {
            return null;
        }

        return $this->sync((int) $profile['cdc_id']);
    }

    public function unassign_teacher($teacher_id)
    {
        $profile = $this->db->raw(
            'SELECT cdc_id FROM teacher_profiles WHERE user_id = ? FOR UPDATE',
            [(int) $teacher_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$profile || $profile['cdc_id'] === null) {
            return null;
        }

        $center_id = (int) $profile['cdc_id'];
        $this->db->raw(
            'UPDATE teacher_profiles
             SET requested_cdc_id = COALESCE(requested_cdc_id, cdc_id),
                 cdc_id = NULL, updated_at = ?
             WHERE user_id = ?',
            [date('Y-m-d H:i:s'), (int) $teacher_id]
        );
        $this->sync($center_id);

        return $center_id;
    }

    private function normalize_name($name)
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) $name)));
    }
}
