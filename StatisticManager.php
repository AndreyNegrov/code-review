<?php


namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Psr\Log\LoggerInterface;
use Redis;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\SerializerInterface;

class StatisticsManager
{
    public const SITE_STATISTICS_KEY = 'site::statistics';
    public const SITE_STATISTICS_TIMEOUT = 3 * 60 * 60 * 24; //Храним 3 дня

    private EntityManagerInterface $entityManager;
    private EventDispatcherInterface $dispatcher;
    private Redis $redis;
    protected LoggerInterface $logger;
    private AnalyticsRepository $analyticsRepository;
    private SerializerInterface $serializer;

    /**
     * @param EntityManagerInterface $entityManager
     * @param EventDispatcherInterface $dispatcher
     * @param Redis $redis
     * @param LoggerInterface $logger
     * @param AnalyticsRepository $analyticsRepository
     * @param SerializerInterface $serializer
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        EventDispatcherInterface $dispatcher,
        Redis $redis,
        LoggerInterface $logger,
        AnalyticsRepository $analyticsRepository,
        SerializerInterface $serializer
    ) {
        $this->entityManager = $entityManager;
        $this->dispatcher = $dispatcher;
        $this->redis = $redis;
        $this->logger = $logger;
        $this->analyticsRepository = $analyticsRepository;
        $this->serializer = $serializer;
    }

    /**
     * @param AnalyticsFilter $filter
     * @param string|null $cacheKey
     * @return AnalyticsDto
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function getAnalyticsData(AnalyticsFilter $filter, string $cacheKey = null): AnalyticsDto
    {
        if ($filter->getCorporation() === null) {
            $categories = $this->entityManager->getRepository(Category::class)->findBy(['corporation' => null,]);
        } else {
            $categories = $this->entityManager->getRepository(Category::class)->findAll();
        }

        $data = new AnalyticsDto();
        $data->hours = $this->analyticsRepository->getHoursStatistic($filter);
        $data->vacancies = $this->analyticsRepository->getCount(Analytics::TYPE_VACANCIES, $filter);

        if (null === $filter->getCorporation()) {
            $data->organizers = $this->analyticsRepository->getCount(Analytics::TYPE_ORGANIZERS, $filter);
            $data->activeVacancies = $this->analyticsRepository->getCount(Analytics::TYPE_VACANCIES, $filter);
            $data->categories = $categories;
            $data->averageAge = $this->analyticsRepository->getVolunteersAverageAge($filter);
        } else {
            $data->categories = $this->getCorporationCategories($categories, $filter);
            $data->topVolunteersData = $this->analyticsRepository->getTopVolunteersData($filter);
        }

        $this->redis->setex($cacheKey, self::SITE_STATISTICS_TIMEOUT, $this->serializer->serialize($data, 'json'));

        return $data;
    }
}
