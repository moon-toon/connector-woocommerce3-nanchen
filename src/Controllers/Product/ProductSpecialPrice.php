<?php
/**
 * @author    Jan Weskamp <jan.weskamp@jtl-software.com>
 * @copyright 2010-2013 JTL-Software GmbH
 */

namespace JtlWooCommerceConnector\Controllers\Product;

use jtl\Connector\Model\CustomerGroup as CustomerGroupModel;
use jtl\Connector\Model\Identity;
use jtl\Connector\Model\Product as ProductModel;
use jtl\Connector\Model\ProductSpecialPrice as ProductSpecialPriceModel;
use jtl\Connector\Model\ProductSpecialPriceItem;
use JtlWooCommerceConnector\Controllers\BaseController;
use JtlWooCommerceConnector\Controllers\GlobalData\CustomerGroup;
use JtlWooCommerceConnector\Utilities\Db;
use JtlWooCommerceConnector\Utilities\SqlHelper;
use JtlWooCommerceConnector\Utilities\SupportedPlugins;
use JtlWooCommerceConnector\Utilities\Util;

class ProductSpecialPrice extends BaseController
{
    public function pullData(\WC_Product $product, ProductModel $model)
    {
        $specialPrices = [];
        $groupController = (new CustomerGroup);
        
        if (!SupportedPlugins::isActive(SupportedPlugins::PLUGIN_B2B_MARKET)) {
            $salePrice = $product->get_sale_price();
            
            if (!empty($salePrice)) {
                $specialPrices[] = (new ProductSpecialPriceModel())
                    ->setId(new Identity($product->get_id()))
                    ->setProductId(new Identity($product->get_id()))
                    ->setIsActive($product->is_on_sale())
                    ->setConsiderDateLimit(!is_null($product->get_date_on_sale_to()))
                    ->setActiveFromDate($product->get_date_on_sale_from())
                    ->setActiveUntilDate($product->get_date_on_sale_to())
                    ->addItem((new ProductSpecialPriceItem())
                        ->setProductSpecialPriceId(new Identity($product->get_id()))
                        ->setCustomerGroupId(new Identity(CustomerGroup::DEFAULT_GROUP))
                        ->setPriceNet($this->getPriceNet($product->get_sale_price(), $product)
                        )
                    );
            }
        } else {
            $customerGroups = $groupController->pullData();
            
            /** @var CustomerGroupModel $customerGroup */
            foreach ($customerGroups as $cKey => $customerGroup) {
                $items = [];
                
                if ($customerGroup->getId()->getEndpoint() === CustomerGroup::DEFAULT_GROUP) {
                    $salePrice = $product->get_sale_price();
                    
                    if (!empty($salePrice)) {
                        $items [] = (new ProductSpecialPriceItem())
                            ->setProductSpecialPriceId(new Identity($product->get_id()))
                            ->setCustomerGroupId(new Identity(CustomerGroup::DEFAULT_GROUP))
                            ->setPriceNet($this->getPriceNet($salePrice, $product));
                    }
                } else {
                    $groupSlug = $groupController->getSlugById($customerGroup->getId()->getEndpoint());
                    $defaultSpecialPrice = false;
                    $salePrice = $product->get_sale_price();
    
                    if (!empty($salePrice)) {
                        $defaultSpecialPrice = true;
                    }
                    
                    if ($model->getIsMasterProduct()) {
                        $productIdForMeta = $product->get_id();
                        $priceKeyForMeta = sprintf('_jtlwcc_bm_%s_sale_price', $groupSlug);
                    } else {
                        $productIdForMeta = $product->get_parent_id();
                        $priceKeyForMeta = sprintf('_jtlwcc_bm_%s_%s_sale_price', $groupSlug, $product->get_id());
                    }
                    $specialPrice = \get_post_meta($productIdForMeta, $priceKeyForMeta, true);
                    
                    if(!empty($specialPrice)){
                        $specialPrice = $this->getPriceNet($specialPrice, $product);
                    }elseif (empty($specialPrice) && $defaultSpecialPrice){
                        $specialPrice = $this->getPriceNet($salePrice, $product);
                    } else{
                        continue;
                    }
                    
                    $items [] = (new ProductSpecialPriceItem())
                        ->setProductSpecialPriceId(new Identity($product->get_id()))
                        ->setCustomerGroupId($customerGroup->getId())
                        ->setPriceNet($specialPrice);
                }
                
                $specialPrices[] = (new ProductSpecialPriceModel())
                    ->setId(new Identity($product->get_id()))
                    ->setProductId(new Identity($product->get_id()))
                    ->setIsActive($product->is_on_sale())
                    ->setConsiderDateLimit(!is_null($product->get_date_on_sale_to()))
                    ->setActiveFromDate($product->get_date_on_sale_from())
                    ->setActiveUntilDate($product->get_date_on_sale_to())
                    ->setItems($items);
            }
        }
        
        return $specialPrices;
    }
    
    protected function getPriceNet($priceNet, \WC_Product $product)
    {
        $taxRate = Util::getInstance()->getTaxRateByTaxClass($product->get_tax_class());
        
        if (\wc_prices_include_tax() && $taxRate != 0) {
            $netPrice = ((float)$priceNet) / ($taxRate + 100) * 100;
        } else {
            $netPrice = round((float)$priceNet, \wc_get_price_decimals());
        }
        
        return $netPrice;
    }
    
    /*protected function priceNet(\WC_Product $product)
    {
        $taxRate = Util::getInstance()->getTaxRateByTaxClass($product->get_tax_class());
        
        if (\wc_prices_include_tax() && $taxRate != 0) {
            $netPrice = ((float)$product->get_sale_price()) / ($taxRate + 100) * 100;
        } else {
            $netPrice = round((float)$product->get_sale_price(), \wc_get_price_decimals());
        }
        
        return $netPrice;
    }*/
    
    public function pushData(ProductModel $product, \WC_Product $wcProduct)
    {
        $pd = \wc_get_price_decimals();
        $productId = $product->getId()->getEndpoint();
        $masterProductId = $product->getMasterProductId();
        $specialPrices = $product->getSpecialPrices();
        
        if (count($specialPrices) > 0) {
            foreach ($specialPrices as $specialPrice) {
                foreach ($specialPrice->getItems() as $item) {
                    $endpoint = $item->getCustomerGroupId()->getEndpoint();
                    $productType = (new Product)->getType($product);
                    $current_time = time();
                    
                    if ($specialPrice->getConsiderDateLimit()) {
                        $dateTo = is_null($end = $specialPrice->getActiveUntilDate()) ? null : $end->getTimestamp();
                        $dateFrom = is_null($start = $specialPrice->getActiveFromDate()) ? null : $start->getTimestamp();
                    } else {
                        $dateTo = '';
                        $dateFrom = '';
                    }
                    
                    if (\wc_prices_include_tax()) {
                        $salePrice = $item->getPriceNet() * (1 + $product->getVat() / 100);
                    } else {
                        $salePrice = $item->getPriceNet();
                    }
                    
                    if (!Util::getInstance()->isValidCustomerGroup((string)$endpoint)) {
                        continue;
                    }
                    
                    if ($endpoint === CustomerGroup::DEFAULT_GROUP) {
                        $salePriceMetaKey = '_sale_price';
                        $salePriceDatesToKey = '_sale_price_dates_to';
                        $salePriceDatesFromKey = '_sale_price_dates_from';
                        $priceMetaKey = '_price';
                        $regularPriceKey = '_regular_price';
                        
                        \update_post_meta($productId, $salePriceMetaKey, \wc_format_decimal($salePrice, $pd),
                            \get_post_meta($productId, $salePriceMetaKey, true));
                        
                        \update_post_meta($productId, $salePriceDatesToKey, $dateTo,
                            \get_post_meta($productId, $salePriceDatesToKey, true));
                        
                        \update_post_meta($productId, $salePriceDatesFromKey, $dateFrom,
                            \get_post_meta($productId, $salePriceDatesFromKey, true));
                        
                        if ('' !== $salePrice && '' == $dateTo && '' == $dateFrom && isset($priceMetaKey)) {
                            \update_post_meta($productId, $priceMetaKey, \wc_format_decimal($salePrice, $pd),
                                \get_post_meta($productId, $priceMetaKey, true));
                        }
                        
                        if ('' !== $salePrice && $dateFrom <= $current_time && $current_time <= $dateTo) {
                            \update_post_meta(
                                $productId,
                                $priceMetaKey,
                                \wc_format_decimal($salePrice, $pd),
                                \get_post_meta($productId, $priceMetaKey, true)
                            );
                        } else {
                            $regularPrice = (float)\get_post_meta($productId, $regularPriceKey, true);
                            \update_post_meta(
                                $productId,
                                $priceMetaKey,
                                \wc_format_decimal($regularPrice, $pd),
                                \get_post_meta($productId, $priceMetaKey, true)
                            );
                        }
                        
                    } elseif (is_int((int)$endpoint)) {
                        if ($productType !== 'variable') {
                            $customerGroup = get_post($endpoint);
                            $priceMetaKey = sprintf(
                                'bm_%s_price',
                                $customerGroup->post_name
                            );
                            $regularPriceMetaKey = sprintf(
                                '_jtlwcc_bm_%s_regular_price',
                                $customerGroup->post_name
                            );
                            
                            $metaKeyForCustomerGroupPriceType = $priceMetaKey . '_type';
                            \update_post_meta(
                                $productId,
                                $metaKeyForCustomerGroupPriceType,
                                'fix',
                                \get_post_meta($productId, $metaKeyForCustomerGroupPriceType, true)
                            );
                            
                            if ($productType === 'product_variation') {
                                $COPpriceMetaKey = sprintf(
                                    'bm_%s_%s_price',
                                    $customerGroup->post_name,
                                    $productId
                                );
                                $COPpriceTypeMetaKey = sprintf(
                                    'bm_%s_%s_price_type',
                                    $customerGroup->post_name,
                                    $productId
                                );
                                $COPsalePriceMetaKey = sprintf(
                                    '_jtlwcc_bm_%s_%s_sale_price',
                                    $customerGroup->post_name,
                                    $productId
                                );
                                $COPsalePriceDatesToKey = sprintf('_jtlwcc_bm_%s_%s_sale_price_dates_to',
                                    $customerGroup->post_name,
                                    $productId
                                );
                                $COPsalePriceDatesFromKey = sprintf('_jtlwcc_bm_%s_%s_sale_price_dates_from',
                                    $customerGroup->post_name,
                                    $productId
                                );
                            } else {
                                $salePriceMetaKey = sprintf(
                                    '_jtlwcc_bm_%s_sale_price',
                                    $customerGroup->post_name
                                );
                                $salePriceDatesToKey = sprintf(
                                    '_jtlwcc_bm_%s_sale_price_dates_to',
                                    $customerGroup->post_name
                                );
                                $salePriceDatesFromKey = sprintf(
                                    '_jtlwcc_bm_%s_sale_price_dates_from',
                                    $customerGroup->post_name
                                );
                            }
                            
                            if ('' !== $salePrice && '' == $dateTo && '' == $dateFrom) {
                                \update_post_meta(
                                    $productId,
                                    $priceMetaKey,
                                    \wc_format_decimal($salePrice, $pd),
                                    \get_post_meta($productId, $priceMetaKey, true)
                                );
                                
                                if ($productType === 'product_variation'
                                    && isset($COPpriceMetaKey)
                                    && isset($COPpriceTypeMetaKey)
                                    && isset($COPsalePriceMetaKey)
                                ) {
                                    //Update price on parent
                                    \update_post_meta(
                                        $masterProductId->getEndpoint(),
                                        $COPpriceMetaKey,
                                        \wc_format_decimal($salePrice, $pd),
                                        \get_post_meta(
                                            $masterProductId->getEndpoint(),
                                            $COPpriceMetaKey,
                                            true
                                        )
                                    );
                                    //Update price type on parent
                                    \update_post_meta(
                                        $masterProductId->getEndpoint(),
                                        $COPpriceTypeMetaKey,
                                        'fix',
                                        \get_post_meta(
                                            $masterProductId->getEndpoint(),
                                            $COPpriceTypeMetaKey,
                                            true
                                        )
                                    );
                                    //Update sale_price on parent
                                    \update_post_meta(
                                        $masterProductId->getEndpoint(),
                                        $COPsalePriceMetaKey,
                                        \wc_format_decimal($salePrice, $pd),
                                        \get_post_meta(
                                            $masterProductId->getEndpoint(),
                                            $COPsalePriceMetaKey,
                                            true
                                        )
                                    );
                                } else {
                                    if (isset($salePriceMetaKey)) {
                                        //Update sale_price on product
                                        \update_post_meta(
                                            $productId,
                                            $salePriceMetaKey,
                                            \wc_format_decimal($salePrice, $pd),
                                            \get_post_meta(
                                                $productId,
                                                $salePriceMetaKey,
                                                true
                                            )
                                        );
                                    }
                                }
                            }
                            
                            if ('' !== $salePrice && $dateFrom <= $current_time && $current_time <= $dateTo) {
                                \update_post_meta(
                                    $productId,
                                    $priceMetaKey,
                                    \wc_format_decimal($salePrice, $pd),
                                    \get_post_meta($productId, $priceMetaKey, true)
                                );
                                
                                if ($productType === 'product_variation'
                                    && isset($COPpriceMetaKey)
                                    && isset($COPpriceTypeMetaKey)
                                    && isset($COPsalePriceMetaKey)
                                    && isset($COPsalePriceDatesToKey)
                                    && isset($COPsalePriceDatesFromKey)
                                ) {
                                    //Update price on parent
                                    \update_post_meta(
                                        $masterProductId->getEndpoint(),
                                        $COPpriceMetaKey,
                                        \wc_format_decimal($salePrice, $pd),
                                        \get_post_meta(
                                            $masterProductId->getEndpoint(),
                                            $COPpriceMetaKey,
                                            true
                                        )
                                    );
                                    //Update price type on parent
                                    \update_post_meta(
                                        $masterProductId->getEndpoint(),
                                        $COPpriceTypeMetaKey,
                                        'fix',
                                        \get_post_meta(
                                            $masterProductId->getEndpoint(),
                                            $COPpriceTypeMetaKey,
                                            true
                                        )
                                    );
                                    //Update sale_price on parent
                                    \update_post_meta(
                                        $masterProductId->getEndpoint(),
                                        $COPsalePriceMetaKey,
                                        \wc_format_decimal($salePrice, $pd),
                                        \get_post_meta(
                                            $masterProductId->getEndpoint(),
                                            $COPsalePriceMetaKey,
                                            true
                                        )
                                    );
                                    //Update sale_price_date_to on parent
                                    \update_post_meta(
                                        $masterProductId->getEndpoint(),
                                        $COPsalePriceDatesToKey,
                                        $dateTo,
                                        \get_post_meta(
                                            $masterProductId->getEndpoint(),
                                            $COPsalePriceDatesToKey,
                                            true
                                        )
                                    );
                                    //Update sale_price_date_from on parent
                                    \update_post_meta(
                                        $masterProductId->getEndpoint(),
                                        $COPsalePriceDatesFromKey,
                                        $dateFrom,
                                        \get_post_meta(
                                            $masterProductId->getEndpoint(),
                                            $COPsalePriceDatesFromKey,
                                            true
                                        )
                                    );
                                } else {
                                    if (
                                        isset($salePriceMetaKey)
                                        && isset($salePriceDatesToKey)
                                        && isset($salePriceDatesFromKey)
                                    ) {
                                        //Update sale_price on product
                                        \update_post_meta(
                                            $productId,
                                            $salePriceMetaKey,
                                            \wc_format_decimal($salePrice, $pd),
                                            \get_post_meta(
                                                $productId,
                                                $salePriceMetaKey,
                                                true
                                            )
                                        );
                                        //Update sale_price_date_to on product
                                        \update_post_meta(
                                            $productId,
                                            $salePriceDatesToKey,
                                            $dateTo,
                                            \get_post_meta(
                                                $productId,
                                                $salePriceDatesToKey,
                                                true
                                            )
                                        );
                                        //Update sale_price_date_from on product
                                        \update_post_meta(
                                            $productId,
                                            $salePriceDatesFromKey,
                                            $dateFrom,
                                            \get_post_meta(
                                                $productId,
                                                $salePriceDatesFromKey,
                                                true
                                            )
                                        );
                                    }
                                }
                            } else {
                                $regularPrice = (float)\get_post_meta($productId, $regularPriceMetaKey, true);
                                \update_post_meta(
                                    $productId,
                                    $priceMetaKey,
                                    \wc_format_decimal($regularPrice, $pd),
                                    \get_post_meta($productId, $priceMetaKey, true)
                                );
                            }
                        }
                    } else {
                        continue;
                    }
                }
            }
        } else {
            
            $customerGroups = Db::getInstance()->query(SqlHelper::customerGroupPull());
            $productType = (new Product)->getType($product);
            
            foreach ($customerGroups as $groupKey => $customerGroup) {
                if (is_int((int)$customerGroup['ID'])) {
                    $priceMetaKey = sprintf(
                        'bm_%s_price',
                        $customerGroup['post_name']
                    );
                    $regularPriceMetaKey = sprintf(
                        '_jtlwcc_bm_%s_regular_price',
                        $customerGroup['post_name']
                    );
                    
                    $metaKeyForCustomerGroupPriceType = $priceMetaKey . '_type';
                    \update_post_meta(
                        $productId,
                        $metaKeyForCustomerGroupPriceType,
                        'fix',
                        \get_post_meta($productId, $metaKeyForCustomerGroupPriceType, true)
                    );
                    
                    if ($productType === 'product_variation') {
                        $COPpriceMetaKey = sprintf(
                            'bm_%s_%s_price',
                            $customerGroup['post_name'],
                            $productId
                        );
                        $COPpriceTypeMetaKey = sprintf(
                            'bm_%s_%s_price_type',
                            $customerGroup['post_name'],
                            $productId
                        );
                        $COPsalePriceMetaKey = sprintf(
                            '_jtlwcc_bm_%s_%s_sale_price',
                            $customerGroup['post_name'],
                            $productId
                        );
                        $COPsalePriceDatesToKey = sprintf('_jtlwcc_bm_%s_%s_sale_price_dates_to',
                            $customerGroup['post_name'],
                            $productId
                        );
                        $COPsalePriceDatesFromKey = sprintf('_jtlwcc_bm_%s_%s_sale_price_dates_from',
                            $customerGroup['post_name'],
                            $productId
                        );
                        
                        \delete_post_meta($masterProductId->getEndpoint(), $COPsalePriceMetaKey,
                            \get_post_meta($masterProductId->getEndpoint(), $COPsalePriceMetaKey, true));
                        \delete_post_meta($masterProductId->getEndpoint(), $COPsalePriceDatesToKey,
                            \get_post_meta($masterProductId->getEndpoint(), $COPsalePriceDatesToKey, true));
                        \delete_post_meta($masterProductId->getEndpoint(), $COPsalePriceDatesFromKey,
                            \get_post_meta($masterProductId->getEndpoint(), $COPsalePriceDatesFromKey, true));
                    } else {
                        $salePriceMetaKey = sprintf(
                            '_jtlwcc_bm_%s_sale_price',
                            $customerGroup['post_name']
                        );
                        $salePriceDatesToKey = sprintf(
                            '_jtlwcc_bm_%s_sale_price_dates_to',
                            $customerGroup['post_name']
                        );
                        $salePriceDatesFromKey = sprintf(
                            '_jtlwcc_bm_%s_sale_price_dates_from',
                            $customerGroup['post_name']
                        );
                        \delete_post_meta($productId, $salePriceMetaKey,
                            \get_post_meta($productId, $salePriceMetaKey, true));
                        \delete_post_meta($productId, $salePriceDatesToKey,
                            \get_post_meta($productId, $salePriceDatesToKey, true));
                        \delete_post_meta($productId, $salePriceDatesFromKey,
                            \get_post_meta($productId, $salePriceDatesFromKey, true));
                    }
                    
                    $regularPrice = (float)\get_post_meta($productId, $regularPriceMetaKey, true);
                    
                } elseif ($customerGroup['ID'] === CustomerGroup::DEFAULT_GROUP) {
                    $salePriceMetaKey = '_sale_price';
                    $salePriceDatesToKey = '_sale_price_dates_to';
                    $salePriceDatesFromKey = '_sale_price_dates_from';
                    $priceMetaKey = '_price';
                    $regularPriceKey = '_regular_price';
                    $regularPrice = (float)\get_post_meta($productId, $regularPriceKey, true);
                    
                    \update_post_meta($productId, $salePriceMetaKey, '',
                        \get_post_meta($productId, $salePriceMetaKey, true));
                    \update_post_meta($productId, $salePriceDatesToKey, '',
                        \get_post_meta($productId, $salePriceDatesToKey, true));
                    \update_post_meta($productId, $salePriceDatesFromKey, '',
                        \get_post_meta($productId, $salePriceDatesFromKey, true));
                } else {
                    continue;
                }
                
                \update_post_meta($productId, $priceMetaKey, \wc_format_decimal($regularPrice, $pd),
                    \get_post_meta($productId, $priceMetaKey, true));
                
                if ($productType === 'product_variation' && isset($COPpriceTypeMetaKey) && isset($COPpriceMetaKey)) {
                    \update_post_meta(
                        $masterProductId->getEndpoint(),
                        $COPpriceMetaKey,
                        \wc_format_decimal($regularPrice, $pd),
                        \get_post_meta($masterProductId->getEndpoint(), $COPpriceMetaKey, true)
                    );
                    \update_post_meta(
                        $masterProductId->getEndpoint(),
                        $COPpriceTypeMetaKey,
                        'fix',
                        \get_post_meta($masterProductId->getEndpoint(), $COPpriceTypeMetaKey, true)
                    );
                }
            }
        }
    }
}
