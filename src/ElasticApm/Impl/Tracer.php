<?php

declare(strict_types=1);

namespace Elastic\Apm\Impl;

use Closure;
use Elastic\Apm\DistributedTracingData;
use Elastic\Apm\ElasticApm;
use Elastic\Apm\Impl\BackendComm\EventSender;
use Elastic\Apm\Impl\Config\AllOptionsMetadata;
use Elastic\Apm\Impl\Config\CompositeRawSnapshotSource;
use Elastic\Apm\Impl\Config\EnvVarsRawSnapshotSource;
use Elastic\Apm\Impl\Config\IniRawSnapshotSource;
use Elastic\Apm\Impl\Config\Parser as ConfigParser;
use Elastic\Apm\Impl\Config\Snapshot as ConfigSnapshot;
use Elastic\Apm\Impl\Log\Backend as LogBackend;
use Elastic\Apm\Impl\Log\Level as LogLevel;
use Elastic\Apm\Impl\Log\LogCategory;
use Elastic\Apm\Impl\Log\LoggableInterface;
use Elastic\Apm\Impl\Log\Logger;
use Elastic\Apm\Impl\Log\LoggerFactory;
use Elastic\Apm\Impl\Log\LogStreamInterface;
use Elastic\Apm\Impl\Util\ElasticApmExtensionUtil;
use Elastic\Apm\Impl\Util\TextUtil;
use Elastic\Apm\TransactionInterface;
use Throwable;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class Tracer implements TracerInterface, LoggableInterface
{
    /** @var TracerDependencies */
    private $providedDependencies;

    /** @var ClockInterface */
    private $clock;

    /** @var EventSinkInterface */
    private $eventSink;

    /** @var ConfigSnapshot */
    private $config;

    /** @var LogBackend */
    private $logBackend;

    /** @var LoggerFactory */
    private $loggerFactory;

    /** @var Logger */
    private $logger;

    /** @var Transaction|null */
    private $currentTransaction = null;

    /** @var bool */
    private $isRecording = true;

    /** @var Metadata */
    private $currentMetadata;

    /** @var HttpDistributedTracing */
    private $httpDistributedTracing;

    public function __construct(TracerDependencies $providedDependencies)
    {
        $this->providedDependencies = $providedDependencies;

        $this->config = $this->buildConfig();

        $this->logBackend = new LogBackend($this->config->effectiveLogLevel(), $providedDependencies->logSink);
        $this->loggerFactory = new LoggerFactory($this->logBackend);
        $this->logger = $this->loggerFactory
            ->loggerForClass(LogCategory::PUBLIC_API, __NAMESPACE__, __CLASS__, __FILE__)->addContext('this', $this);

        ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
        && $loggerProxy->log(
            'Constructing Tracer...',
            [
                'Version of agent PHP part' => ElasticApm::VERSION,
                'PHP_VERSION'               => PHP_VERSION,
                'providedDependencies'      => $providedDependencies,
                'effectiveLogLevel'         => LogLevel::intToName($this->config->effectiveLogLevel()),
            ]
        );

        $this->clock = $providedDependencies->clock ?? Clock::singletonInstance();

        $this->eventSink = $providedDependencies->eventSink ??
                           (ElasticApmExtensionUtil::isLoaded()
                               ? new EventSender($this->config, $this->loggerFactory)
                               : NoopEventSink::singletonInstance());

        $this->currentMetadata = MetadataDiscoverer::discoverMetadata($this->config);

        $this->httpDistributedTracing = new HttpDistributedTracing($this->loggerFactory);

        ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
        && $loggerProxy->log('Constructed Tracer successfully');
    }

    private function buildConfig(): ConfigSnapshot
    {
        $rawSnapshotSource
            = $this->providedDependencies->configRawSnapshotSource
              ?? new CompositeRawSnapshotSource(
                  [
                      new IniRawSnapshotSource(IniRawSnapshotSource::DEFAULT_PREFIX),
                      new EnvVarsRawSnapshotSource(EnvVarsRawSnapshotSource::DEFAULT_NAME_PREFIX),
                  ]
              );

        $parsingLoggerFactory
            = new LoggerFactory(new LogBackend(LogLevel::TRACE, $this->providedDependencies->logSink));
        $parser = new ConfigParser($parsingLoggerFactory);
        $allOptsMeta = AllOptionsMetadata::build();
        return new ConfigSnapshot($parser->parse($allOptsMeta, $rawSnapshotSource->currentSnapshot($allOptsMeta)));
    }

    public function getConfig(): ConfigSnapshot
    {
        return $this->config;
    }

    private function beginTransactionImpl(
        string $name,
        string $type,
        ?float $timestamp,
        ?DistributedTracingData $distributedTracingData
    ): ?Transaction {
        if (!$this->isRecording) {
            return null;
        }

        return new Transaction($this, $name, $type, $timestamp, $distributedTracingData);
    }

    /** @inheritDoc */
    public function beginCurrentTransaction(
        string $name,
        string $type,
        ?float $timestamp = null,
        ?DistributedTracingData $distributedTracingData = null
    ): TransactionInterface {
        if (!is_null($this->currentTransaction)) {
            ($loggerProxy = $this->logger->ifWarningLevelEnabled(__LINE__, __FUNCTION__))
            && $loggerProxy->log(
                'Received request to begin a new current transaction'
                . ' even though there is a current transaction that is still not ended',
                ['this' => $this]
            );
        }

        $this->currentTransaction = $this->beginTransactionImpl($name, $type, $timestamp, $distributedTracingData);
        if (is_null($this->currentTransaction)) {
            return NoopTransaction::singletonInstance();
        }

        return $this->currentTransaction;
    }

    /** @inheritDoc */
    public function captureCurrentTransaction(
        string $name,
        string $type,
        Closure $callback,
        ?float $timestamp = null,
        ?DistributedTracingData $distributedTracingData = null
    ) {
        $newTransaction = $this->beginCurrentTransaction($name, $type, $timestamp, $distributedTracingData);
        try {
            return $callback($newTransaction);
        } catch (Throwable $throwable) {
            $newTransaction->createError($throwable);
            throw $throwable;
        } finally {
            $newTransaction->end();
        }
    }

    public function getCurrentTransaction(): TransactionInterface
    {
        return $this->currentTransaction ?? NoopTransaction::singletonInstance();
    }

    public function resetCurrentTransaction(): void
    {
        $this->currentTransaction = null;
    }

    /** @inheritDoc */
    public function beginTransaction(
        string $name,
        string $type,
        ?float $timestamp = null,
        ?DistributedTracingData $distributedTracingData = null
    ): TransactionInterface {
        $newTransaction = $this->beginTransactionImpl($name, $type, $timestamp, $distributedTracingData);

        if (is_null($newTransaction)) {
            return NoopTransaction::singletonInstance();
        }

        return $newTransaction;
    }

    /** @inheritDoc */
    public function captureTransaction(
        string $name,
        string $type,
        Closure $callback,
        ?float $timestamp = null,
        ?DistributedTracingData $distributedTracingData = null
    ) {
        $newTransaction = $this->beginTransaction($name, $type, $timestamp, $distributedTracingData);
        try {
            return $callback($newTransaction);
        } catch (Throwable $throwable) {
            $newTransaction->createError($throwable);
            /** @noinspection PhpUnhandledExceptionInspection */
            throw $throwable;
        } finally {
            $newTransaction->end();
        }
    }

    /** @inheritDoc */
    public function createError(Throwable $throwable): ?string
    {
        if (is_null($this->currentTransaction)) {
            return $this->doCreateError($throwable, /* transaction */ null, /* span */ null);
        }

        return $this->currentTransaction->createError($throwable);
    }

    public function doCreateError(?Throwable $throwable, ?Transaction $transaction, ?Span $span): ?string
    {
        if (!$this->isRecording) {
            return null;
        }

        $isGoingToBeSentWithTransaction = !is_null($transaction) && !$transaction->hasEnded();

        // PHPStan cannot deduce that $transaction is not null
        // if $isGoingToBeSentWithTransaction is true
        // @phpstan-ignore-next-line
        if ($isGoingToBeSentWithTransaction && !$transaction->reserveSpaceInErrorToSendQueue()) {
            return null;
        }

        $newError = ErrorData::build(/* tracer: */ $this, $throwable, $transaction, $span);

        if ($isGoingToBeSentWithTransaction) {
            // PHPStan cannot deduce that $transaction is not null
            // if $isGoingToBeSentWithTransaction is true
            assert(!is_null($transaction));
            $transaction->queueErrorDataToSend($newError);
        } else {
            $this->sendEventsToApmServer(/* spansData */ [], [$newError], /* transaction: */ null);
        }
        return $newError->id;
    }

    public function getClock(): ClockInterface
    {
        return $this->clock;
    }

    public function isNoop(): bool
    {
        return false;
    }

    public static function limitKeywordString(string $keywordString): string
    {
        return TextUtil::ensureMaxLength($keywordString, Constants::KEYWORD_STRING_MAX_LENGTH);
    }

    public static function limitNullableKeywordString(?string $keywordString): ?string
    {
        if (is_null($keywordString)) {
            return null;
        }
        return self::limitKeywordString($keywordString);
    }

    public function limitNonKeywordString(string $nonKeywordString): string
    {
        return TextUtil::ensureMaxLength($nonKeywordString, Constants::NON_KEYWORD_STRING_MAX_LENGTH);
    }

    public function loggerFactory(): LoggerFactory
    {
        return $this->loggerFactory;
    }

    public function httpDistributedTracing(): HttpDistributedTracing
    {
        return $this->httpDistributedTracing;
    }

    public function pauseRecording(): void
    {
        $this->isRecording = false;
    }

    public function resumeRecording(): void
    {
        $this->isRecording = true;
    }

    public function isRecording(): bool
    {
        return $this->isRecording;
    }

    /**
     * @param SpanData[]           $spansData
     * @param ErrorData[]          $errorsData
     * @param TransactionData|null $transactionData
     */
    public function sendEventsToApmServer(array $spansData, array $errorsData, ?TransactionData $transactionData): void
    {
        $this->eventSink->consume($this->currentMetadata, $spansData, $errorsData, $transactionData);
    }

    public function setAgentEphemeralId(?string $ephemeralId): void
    {
        assert(isset($this->currentMetadata->service->agent));
        $this->currentMetadata->service->agent->ephemeralId = $this->limitNullableKeywordString($ephemeralId);
    }

    public function toLog(LogStreamInterface $stream): void
    {
        $result = [
            'isRecording'          => $this->isRecording,
            'providedDependencies' => $this->providedDependencies,
            'config'               => $this->config,
        ];

        if (!is_null($this->currentTransaction)) {
            $result['currentTransactionId'] = $this->currentTransaction->getId();
        }

        $stream->toLogAs($result);
    }
}
