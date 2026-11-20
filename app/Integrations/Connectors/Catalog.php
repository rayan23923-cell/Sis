<?php

namespace App\Integrations\Connectors;

/**
 * The connectors SIS knows, each with its tools — like the connectors in Claude: the admin connects one,
 * then decides which of its tools may run. A tool that is off is refused at the door.
 *
 * direction: out = SIS calls the other system with its key; in = the other system calls SIS with a key SIS issues.
 */
class Catalog
{
    public const CONNECTORS = [
        'library' => [
            'name' => 'المكتبة',
            'product' => 'FileNest',
            'description' => 'يربط النظام بمكتبة FileNest: الطلبة يصبحون قرّاءً تلقائياً، وتظهر الكتب غير المرجعة والغرامات في صفحة الطالب وطلبات الوثائق.',
            'direction' => 'out',
            'url_required' => true,
            'tools' => [
                'members_sync' => [
                    'name' => 'مزامنة القرّاء',
                    'description' => 'يرسل الطلبة المستمرين إلى المكتبة كقرّاء: الاسم، الرقم الجامعي (رقم البطاقة)، المرحلة، الهاتف والبريد.',
                    'access' => 'write',
                ],
                'clearance' => [
                    'name' => 'براءة الذمة',
                    'description' => 'يقرأ من المكتبة عدد الكتب غير المرجعة والمتأخرة والغرامات غير المدفوعة لكل طالب.',
                    'access' => 'read',
                ],
            ],
        ],
        'exams' => [
            'name' => 'نظام الامتحانات',
            'product' => 'ExamNest',
            'description' => 'يسمح لنظام الامتحانات بقراءة المواد وقوائم الطلبة، وإرسال درجات الامتحانات. الدرجات لا تدخل السجل إلا بعد موافقة تدريسي المادة.',
            'direction' => 'in',
            'url_required' => false,
            'tools' => [
                'rosters' => [
                    'name' => 'المواد وقوائم الطلبة',
                    'description' => 'قراءة شعب الفصل الحالي ومكونات التقييم والطلبة المشمولين بكل دور (الرقم الجامعي والاسم فقط).',
                    'access' => 'read',
                ],
                'marks_inbox' => [
                    'name' => 'استلام الدرجات',
                    'description' => 'استلام درجات امتحان أو مكوّن تقييم. تُعرض على التدريسي في صفحة المادة ليعتمدها أو يرفضها.',
                    'access' => 'write',
                ],
            ],
        ],
    ];

    public static function has(string $key): bool
    {
        return isset(self::CONNECTORS[$key]);
    }

    public static function get(string $key): array
    {
        return self::CONNECTORS[$key];
    }

    public static function toolKeys(string $key): array
    {
        return array_keys(self::CONNECTORS[$key]['tools']);
    }

    /** Everything is on when first connected; the admin switches off what is not wanted. */
    public static function defaultTools(string $key): array
    {
        return self::toolKeys($key);
    }
}
