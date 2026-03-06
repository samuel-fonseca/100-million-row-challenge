<?php

namespace App;

use RuntimeException;

final class Parser
{
    private int $availableWorkers = 8;

    public function parse(string $inputPath, string $outputPath): void
    {
        $this->availableWorkers = $this->getCoreCount();
        $pids = [];

        for ($i = 0; $i < $this->availableWorkers; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Fork failed.');
            }

            if ($pid === 0) {
                $this->processChunk($inputPath, $i);
                exit;
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $this->mergeOutputChunks($outputPath);
    }

    private function processChunk(string $filePath, int $index): void
    {
        $fileSize = filesize($filePath);
        $chunkSize = (int) ($fileSize / $this->availableWorkers);

        $start = $index * $chunkSize;
        $end = ($index === $this->availableWorkers - 1) ? $fileSize : $start + $chunkSize;

        $handle = fopen($filePath, 'r');
        fseek($handle, $start);

        if ($start !== 0) {
            fgets($handle);
        }

        $urlMap = [];
        $bufferSize = 8 * 1024 * 1024;
        $bytesRead = 0;
        $chunkLength = $end - ftell($handle);
        $leftover = '';

        while ($bytesRead < $chunkLength) {
            $readSize = min($bufferSize, $chunkLength - $bytesRead);
            $chunk = fread($handle, $readSize);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $bytesRead += strlen($chunk);

            $this->readChunk(
                urlMap: $urlMap,
                chunk: $chunk,
                leftover: $leftover,
            );
        }

        if ($leftover !== '') {
            $rest = fgets($handle);
            if($rest !== false) {
                $leftover .= rtrim($rest, "\r\n");
            }

            $this->parseCsvRow($urlMap, $leftover);
        }

        $tempPath = sprintf('%s/../data/p%s_worker.json', __DIR__, $index);
        file_put_contents($tempPath, serialize($urlMap));
        fclose($handle);

        exit;
    }

    private function readChunk(array &$urlMap, string $chunk, string &$leftover): void {
        $data = $leftover.$chunk;
        $dataLen = strlen($data);
        $offset = 0;

        while(($newLine = strpos($data, "\n", $offset)) !== false) {
            $line = substr($data, $offset, $newLine - $offset);

            if ($line !== '' && $line[-1] === "\r") {
                $line = substr($line, 0, -1);
            }

            if ($line !== '') {
                $this->parseCsvRow($urlMap, $line);
            }

            $offset = $newLine + 1;
        }

        $leftover = $offset < $dataLen ? substr($data, $offset) : '';
    }

    private function parseCsvRow(array &$map, string $line): void
    {
        $commaPosition = strpos($line, ',');
        $urlPath = substr($line, 19, $commaPosition - 19);
        $datePath = substr($line, $commaPosition + 1, 10);

        $map[$urlPath][$datePath] = ($map[$urlPath][$datePath] ?? 0) + 1;
    }

    private function mergeOutputChunks(string $outputPath): void
    {
        $merged = [];

        for ($i = 0; $i < $this->availableWorkers; $i++) {
            $tempPath = sprintf('%s/../data/p%s_worker.json', __DIR__, $i);
            $partial = unserialize(file_get_contents($tempPath));

            foreach ($partial as $urlPath => $dates) {
                foreach ($dates as $date => $count) {
                    $merged[$urlPath][$date] = ($merged[$urlPath][$date] ?? 0) + $count;
                }
            }

            unlink($tempPath);
        }

        foreach ($merged as &$dates) {
            ksort($dates);
        }

        file_put_contents(
            filename: $outputPath,
            data: json_encode($merged, JSON_PRETTY_PRINT)
        );
    }

    private function getCoreCount(): int
    {
        $cores = match (PHP_OS_FAMILY) {
            'Darwin' => (int) shell_exec('sysctl -n hw.physicalcpu'),
            'Linux' => (int) shell_exec('nproc'),
            default => 4,
        };

        return $cores > 0 ? $cores : 4;
    }
}