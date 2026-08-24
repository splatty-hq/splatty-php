<?php

declare(strict_types=1);

namespace Splatty\Logs;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level as MonologLevel;
use Monolog\LogRecord;
use Splatty\Hub;

/**
 * Forwards Monolog output to Splatty. The Monolog counterpart of the Ruby
 * client's SemanticLogger appender.
 *
 * Requires monolog/monolog ^3.
 *
 *     $logger->pushHandler(new MonologHandler());
 */
final class MonologHandler extends AbstractProcessingHandler
{
    public function __construct(int|string|MonologLevel $level = MonologLevel::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $client = Hub::getClient();
        if ($client === null) {
            return;
        }

        $fields = array_merge($record->context, $record->extra);
        if ($record->channel !== '') {
            $fields['channel'] = $record->channel;
        }

        $client->captureLog([
            'time' => $record->datetime,
            'level' => $record->level->getName(),
            'message' => $record->message,
            'fields' => $fields,
        ]);
    }
}
