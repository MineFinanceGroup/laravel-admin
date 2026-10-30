<?php

namespace Encore\Admin\Tests\Compatibility;

use Encore\Admin\Actions\BatchAction;
use Encore\Admin\Actions\Interactor\Form;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;

class ActionFormTest extends TestCase
{
    public static function formContents(): array
    {
        return [
            ['<div><label>Причина</label><input name="reason"><span>Подтверждение</span></div>', 'input[name="reason"]'],
            ['<!DOCTYPE html><html><head><title>Action</title></head><body><div><select name="status"><option>Active</option></select></div></body></html>', 'select[name="status"]'],
            ['<input name="reason"><span class="help">Help text</span>', 'input[name="reason"]'],
        ];
    }

    #[DataProvider('formContents')]
    public function testModalAttributeKeepsFormMarkupInsteadOfTheHead(string $html, string $selector): void
    {
        $action = new FormTestBatchAction();
        $form = new Form($action);
        $before = new Crawler($html);
        $result = $form->addElementAttr($html, $selector);
        $after = new Crawler($result);

        $this->assertNotSame('', trim($result));
        $this->assertSame($form->getModalId(), $after->filter($selector)->attr('modal'));
        $this->assertSame($before->filter('body')->text(), $after->filter('body')->text());
        $this->assertStringNotContainsString('<head', $result);
        $this->assertStringNotContainsString('<body', $result);
        $this->assertSame($before->filter('body')->children()->count(), $after->filter('body')->children()->count());
    }
}

class FormTestBatchAction extends BatchAction {}
