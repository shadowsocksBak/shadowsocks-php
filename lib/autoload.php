<?php
/**
 * 自动加载
 */
spl_autoload_register(function ($className)
{
    include __DIR__ . '/' . $className . '.php';
});