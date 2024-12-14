<?php

namespace App\Core\Audit\Enums;

enum AnalysisStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isFinished(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED
        ]);
    }
}

enum AnomalySeverity: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';
    case INFO = 'info';

    public function getColor(): string
    {
        return match($this) {
            self::CRITICAL => '#FF0000',
            self::HIGH => '#FF9900',
            self::MEDIUM => '#FFFF00',
            self::LOW => '#00FF00',
            self::INFO => '#0000FF'
        };
    }
}

enum PatternType: string
{
    case SEQUENTIAL = 'sequential';
    case TEMPORAL = 'temporal';
    case BEHAVIORAL = 'behavioral';
    case STRUCTURAL = 'structural';
    case CYCLICAL = 'cyclical';

    public function getDetectionStrategy(): string
    {
        return match($this) {
            self::SEQUENTIAL => 'sequence_matching',
            self::TEMPORAL => 'time_series_analysis',
            self::BEHAVIORAL => 'behavior_clustering',
            self::STRUCTURAL => 'structure_analysis',
            self::CYCLICAL => 'cycle_detection'
        };
    }
}

enum AnalysisType: string
{
    case STATISTICAL = 'statistical';
    case PREDICTIVE = 'predictive';
    case DIAGNOSTIC = 'diagnostic';
    case PRESCRIPTIVE = 'prescriptive';
    case DESCRIPTIVE = 'descriptive';

    public function getRequiredMetrics(): array
    {
        return match($this) {
            self::STATISTICAL => ['mean', 'median', 'std_dev', 'variance'],
            self::PREDICTIVE => ['accuracy', 'precision', 'recall', 'f1_score'],
            self::DIAGNOSTIC => ['root_cause', 'impact', 'correlation'],
            self::PRESCRIPTIVE => ['recommendation', 'confidence', 'impact'],
            self::DESCRIPTIVE => ['summary', 'distribution', 'trends']
        };
    }
}

enum MetricType: string
{
    case COUNTER = 'counter';
    case GAUGE = 'gauge';
    case HISTOGRAM = 'histogram';
    case SUMMARY = 'summary';

    public function getAggregationMethod(): string
    {
        return match($this) {
            self::COUNTER => 'sum',
            self::GAUGE => 'last',
            self::HISTOGRAM => 'distribution',
            self::SUMMARY => 'percentiles'
        };
    }
}

enum ValidationRule: string
{
    case REQUIRED = 'required';
    case NUMERIC = 'numeric';
    case STRING = 'string';
    case ARRAY = 'array';
    case BOOLEAN = 'boolean';
    case DATE = 'date';
    case EMAIL = 'email';
    case URL = 'url';

    public function getValidationMethod(): string
    {
        return sprintf('validate%s', ucfirst(strtolower($this->value)));
    }
}

enum NotificationType: string
{
    case EMAIL = 'email';
    case SLACK = 'slack';
    case WEBHOOK = 'webhook';
    case SMS = 'sms';
    case PUSH = 'push';

    public function getFormatter(): string
    {
        return match($this) {
            self::EMAIL => EmailFormatter::class,
            self::SLACK => SlackFormatter::class,
            self::WEBHOOK => WebhookFormatter::class,
            self::SMS => SMSFormatter::class,
            self::PUSH => PushFormatter::class
        };
    }
}

enum CacheStrategy: string
{
    case NONE = 'none';
    case SIMPLE = 'simple';
    case TAGGED = 'tagged';
    case DISTRIBUTED = 'distributed';

    public function getTtl(): int
    {
        return match($this) {
            self::NONE => 0,
            self::SIMPLE => 3600,
            self::TAGGED => 7200,
            self::DISTRIBUTED => 14400
        };
    }
}

enum ErrorLevel: string
{
    case DEBUG = 'debug';
    case INFO = 'info';
    case WARNING = 'warning';
    case ERROR = 'error';
    case CRITICAL = 'critical';

    public function shouldNotify(): bool
    {
        return in_array($this, [
            self::ERROR,
            self::CRITICAL
        ]);
    }
}

enum ProcessingStage: string
{
    case QUEUED = 'queued';
    case PREPROCESSING = 'preprocessing';
    case PROCESSING = 'processing';
    case POSTPROCESSING = 'postprocessing';
    case COMPLETED = 'completed';

    public function getNextStage(): ?self
    {
        return match($this) {
            self::QUEUED => self::PREPROCESSING,
            self::PREPROCESSING => self::PROCESSING,
            self::PROCESSING => self::POSTPROCESSING,
            self::POSTPROCESSING => self::COMPLETED,
            self::COMPLETED => null
        };
    }
}
