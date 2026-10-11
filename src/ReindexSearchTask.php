<?php

declare(strict_types=1);

namespace SilverShop\Search\Meilisearch;

use SilverShop\Page\Product;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Pushes the index settings and (re)indexes every published product into Meilisearch. Run after install, after
 * changing the indexed fields, or to repair drift.
 */
class ReindexSearchTask extends BuildTask
{
    protected static string $commandName = 'meilisearch-reindex';

    protected string $title = 'Meilisearch: reindex all published products';

    protected static string $description =
        'Pushes index settings and (re)indexes every published product into Meilisearch.';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $backend = Injector::inst()->get(MeilisearchBackend::class);

        if (!$backend->isConfigured()) {
            $output->writeln('Meilisearch is not configured — set SS_MEILISEARCH_HOST (and SS_MEILISEARCH_KEY).');
            return Command::FAILURE;
        }

        $backend->pushSettings();
        $products = Versioned::get_by_stage(Product::class, Versioned::LIVE);
        $backend->index($products);

        $output->writeln('Indexed ' . $products->count() . ' published product(s) into Meilisearch.');

        return Command::SUCCESS;
    }
}
