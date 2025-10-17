<?php

namespace Kiyoh\Reviews\Console\Command;

use Magento\Framework\Console\Cli;
use Magento\Framework\App\State;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class CheckConfigCommand extends Command
{
    private const OPTION_STORE = 'store';

    /**
     * @var State
     */
    private $appState;
    
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        State $appState,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        string $name = null
    ) {
        $this->appState = $appState;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('kiyoh:check-config')
            ->setDescription('Check current Kiyoh Reviews configuration')
            ->addOption(
                self::OPTION_STORE,
                's',
                InputOption::VALUE_OPTIONAL,
                'Store ID to check (default: 1)',
                1
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area already set
        }

        $storeId = (int) $input->getOption(self::OPTION_STORE);

        $output->writeln('');
        $output->writeln('<info>🔍 Kiyoh Reviews Configuration Check</info>');
        $output->writeln('<info>====================================</info>');

        try {
            $store = $this->storeManager->getStore($storeId);
            $output->writeln("Store: {$store->getName()} (ID: {$storeId})");
        } catch (\Exception $e) {
            $output->writeln("<error>❌ Invalid store ID: {$storeId}</error>");
            return Cli::RETURN_FAILURE;
        }

        $output->writeln('');

        // Configuration paths to check
        $configs = [
            'API Settings' => [
                'kiyoh_reviews/api_settings/enabled' => 'Enabled',
                'kiyoh_reviews/api_settings/server' => 'Server',
                'kiyoh_reviews/api_settings/location_id' => 'Location ID',
                'kiyoh_reviews/api_settings/api_token' => 'API Token',
            ],
            'Review Invitations' => [
                'kiyoh_reviews/review_invitations/enabled' => 'Enabled',
                'kiyoh_reviews/review_invitations/invitation_type' => 'Invitation Type',
                'kiyoh_reviews/review_invitations/delay_days' => 'Delay Days',
                'kiyoh_reviews/review_invitations/max_products_per_invite' => 'Max Products per Invite',
                'kiyoh_reviews/review_invitations/fallback_language' => 'Fallback Language',
                'kiyoh_reviews/review_invitations/exclude_customer_groups' => 'Exclude Customer Groups (Optional)',
                'kiyoh_reviews/review_invitations/exclude_product_groups' => 'Exclude Product Groups (Optional)',
            ]
        ];

        $allConfigured = true;
        $criticalMissing = [];

        foreach ($configs as $section => $sectionConfigs) {
            $output->writeln("<comment>{$section}:</comment>");
            
            foreach ($sectionConfigs as $path => $label) {
                $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
                
                // Mask API token for security
                if ($path === 'kiyoh_reviews/api_settings/api_token' && $value) {
                    $displayValue = substr($value, 0, 8) . '...';
                } else {
                    $displayValue = $value;
                }
                
                if ($value !== null && $value !== '') {
                    if (is_bool($value) || $value === '0' || $value === '1') {
                        $status = $value ? '✅ Enabled' : '❌ Disabled';
                    } else {
                        $status = "✅ {$displayValue}";
                    }
                    $output->writeln("   {$label}: {$status}");
                } else {
                    $output->writeln("   {$label}: <error>❌ Not Set</error>");
                    $allConfigured = false;
                    
                    // Mark critical settings
                    if (in_array($path, [
                        'kiyoh_reviews/api_settings/enabled',
                        'kiyoh_reviews/api_settings/server',
                        'kiyoh_reviews/api_settings/location_id',
                        'kiyoh_reviews/api_settings/api_token'
                    ])) {
                        $criticalMissing[] = $label;
                    }
                }
            }
            $output->writeln('');
        }

        // Summary
        if ($allConfigured) {
            $output->writeln('<info>✅ All configuration values are set!</info>');
            $output->writeln('<comment>💡 Run: php bin/magento kiyoh:test-api</comment>');
        } else {
            $output->writeln('<error>⚠️  Some configuration values are missing</error>');
            
            if (!empty($criticalMissing)) {
                $output->writeln('<error>🚨 Critical settings missing:</error>');
                foreach ($criticalMissing as $missing) {
                    $output->writeln("<error>   - {$missing}</error>");
                }
            }
            
            $output->writeln('');
            $output->writeln('<comment>🔧 To configure:</comment>');
            $output->writeln('1. Go to Magento Admin');
            $output->writeln('2. Navigate to: Stores > Configuration');
            $output->writeln('3. Find: Kiyoh Reviews section');
            $output->writeln('4. Enter your API credentials:');
            $output->writeln('   - Server: kiyoh.com');
            $output->writeln('   - API Token: 0bcb3f10-a910-46c7-8c42-f4c3ebff7e0a');
            $output->writeln('   - Location ID: 1080211');
            $output->writeln('5. Enable the features you want to use');
            $output->writeln('6. Save configuration and flush cache');
        }

        $output->writeln('');
        return $allConfigured ? Cli::RETURN_SUCCESS : Cli::RETURN_FAILURE;
    }
}