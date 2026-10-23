<?php

/** Reception's recorded choice, separate from the doctor who sees the patient. */
class VisitDoctorAssignment
{
    /** Write on the form's mysqli connection, inside its patient/visit transaction. */
    public static function saveSource(mysqli $connection, int $visitId, ?string $source): void
    {
        if ($visitId <= 0 || ($source !== null && self::source($source) === null)) {
            throw new InvalidArgumentException('Invalid visit doctor assignment');
        }
        $statement = $connection->prepare('UPDATE visits SET doctor_assignment_source = ? WHERE id = ?');
        if (!$statement) {
            throw new RuntimeException('Could not prepare visit doctor assignment');
        }
        try {
            $statement->bind_param('si', $source, $visitId);
            if (!$statement->execute()) {
                throw new RuntimeException('Could not save visit doctor assignment');
            }
        } finally {
            $statement->close();
        }
    }

    public static function source($value): ?string
    {
        return is_string($value) && in_array($value, ['requested', 'reception'], true) ? $value : null;
    }

    /**
     * Read from the form's requested/seen doctor boxes: a seen doctor the
     * patient did not ask for was chosen by reception.
     */
    public static function derive(?int $requested, ?int $assigned): ?string
    {
        $requested = (int) $requested;
        $assigned = (int) $assigned;
        if ($requested > 0 && ($assigned <= 0 || $assigned === $requested)) {
            return 'requested';
        }
        return $assigned > 0 ? 'reception' : null;
    }
}
