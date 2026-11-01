<?php

namespace app\support;

use think\paginator\driver\Bootstrap;

class Pagination extends Bootstrap
{
    public function render()
    {
        if (!$this->hasPages()) {
            return '';
        }

        $current = $this->currentPage();
        $items = [$this->renderPrevious($current)];

        if ($this->simple) {
            $items[] = '<li class="nm-page-item nm-page-compact nm-page-compact-plain"><span class="nm-page-current">第 ' . $current . ' 页</span></li>';
        } else {
            $last = $this->lastPage();
            foreach ($this->pageWindow($current, $last) as $page) {
                $items[] = $page === null
                    ? '<li class="nm-page-item nm-page-ellipsis-item"><span class="nm-page-ellipsis" aria-hidden="true">…</span></li>'
                    : $this->renderPage($page, $current);
            }
            $items[] = '<li class="nm-page-item nm-page-compact"><span class="nm-page-current">' . $current . ' / ' . $last . '</span></li>';
        }

        $items[] = $this->renderNext($current);

        return '<nav class="nm-pagination' . ($this->simple ? ' nm-pagination-simple' : '') . '" aria-label="分页导航">'
            . '<ul class="nm-pagination-list">' . implode('', $items) . '</ul>'
            . '</nav>';
    }

    protected function pageWindow(int $current, int $last): array
    {
        if ($last <= 7) {
            return range(1, $last);
        }

        if ($current <= 4) {
            return [1, 2, 3, 4, 5, null, $last];
        }

        if ($current >= $last - 3) {
            return [1, null, $last - 4, $last - 3, $last - 2, $last - 1, $last];
        }

        return [1, null, $current - 1, $current, $current + 1, null, $last];
    }

    protected function renderPrevious(int $current): string
    {
        $icon = '<i class="ti ti-arrow-left" aria-hidden="true"></i>';
        $label = '<span class="nm-page-nav-label">上一页</span>';

        if ($current <= 1) {
            return '<li class="nm-page-item"><span class="nm-page-link nm-page-nav is-disabled" aria-disabled="true">' . $icon . $label . '</span></li>';
        }

        return '<li class="nm-page-item"><a class="nm-page-link nm-page-nav" href="' . $this->safeUrl($current - 1) . '" rel="prev" aria-label="上一页" title="上一页">' . $icon . $label . '</a></li>';
    }

    protected function renderNext(int $current): string
    {
        $icon = '<i class="ti ti-arrow-right" aria-hidden="true"></i>';
        $label = '<span class="nm-page-nav-label">下一页</span>';

        if (!$this->hasMore) {
            return '<li class="nm-page-item"><span class="nm-page-link nm-page-nav is-disabled" aria-disabled="true">' . $label . $icon . '</span></li>';
        }

        return '<li class="nm-page-item"><a class="nm-page-link nm-page-nav" href="' . $this->safeUrl($current + 1) . '" rel="next" aria-label="下一页" title="下一页">' . $label . $icon . '</a></li>';
    }

    protected function renderPage(int $page, int $current): string
    {
        if ($page === $current) {
            return '<li class="nm-page-item nm-page-number"><span class="nm-page-link is-active" aria-current="page" aria-label="第 ' . $page . ' 页，当前页">' . $page . '</span></li>';
        }

        return '<li class="nm-page-item nm-page-number"><a class="nm-page-link" href="' . $this->safeUrl($page) . '" aria-label="第 ' . $page . ' 页" title="第 ' . $page . ' 页">' . $page . '</a></li>';
    }

    protected function safeUrl(int $page): string
    {
        return htmlspecialchars($this->url($page), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
