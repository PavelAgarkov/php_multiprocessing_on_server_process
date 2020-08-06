<?php

namespace src;

/** Класс для создания сета однотипных процессов
 * Class WorkerProcess
 * @package src
 */
class WorkerProcess
{
    /**
     * @var mixed|string - имя воркера
     */
    private string $workerName;

    /**
     * @var int|mixed - количетво воркеров в сете
     */
    private int $countWorkers;

    /**
     * @var int|mixed - размер разделяемой памяти для каждого варкера из набора
     */
    private int $memorySize;

    public function __construct(array $workerSettings) {
        $this->workerName = $workerSettings[0];
        $this->countWorkers = $workerSettings[1];
        $this->memorySize = $workerSettings[2];
    }

    /** Метод возвращает количество воркеров
     * @return int
     */
    public function getCountWorkers() : int
    {
        return $this->countWorkers;
    }

    /** Метод возвращает размер разделяемой памяти дял каждого воркера из сета
     * @return int
     */
    public function getMemorySize() : int
    {
        return $this->memorySize;
    }

    /** Метод возвращает имя файла воркера для набора
     * @return string
     */
    public function getWorkerName() : string
    {
        return $this->workerName;
    }

}