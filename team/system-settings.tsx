import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Settings, Globe, Bell, Shield, Database, Mail, Cloud } from 'lucide-react';

const SystemSettings = () => {
  return (
    <div className="w-full space-y-4">
      <Card>
        <CardHeader className="border-b">
          <div className="flex justify-between items-center">
            <CardTitle>تنظیمات سیستم</CardTitle>
            <button className="px-4 py-2 bg-blue-500 text-white rounded">
              ذخیره تغییرات
            </button>
          </div>
        </CardHeader>

        <CardContent>
          <div className="grid grid-cols-3 gap-6">
            {/* General Settings */}
            <Card>
              <CardContent className="p-4">
                <div className="flex items-center space-x-2 mb-4">
                  <Settings className="w-5 h-5 text-gray-500" />
                  <h3 className="font-medium">تنظیمات عمومی</h3>
                </div>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">نام سایت</label>
                    <input type="text" className="mt-1 block w-full border rounded p-2" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">توضیحات سایت</label>
                    <textarea className="mt-1 block w-full border rounded p-2" rows={3}></textarea>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Language Settings */}
            <Card>
              <CardContent className="p-4">
                <div className="flex items-center space-x-2 mb-4">
                  <Globe className="w-5 h-5 text-gray-500" />
                  <h3 className="font-medium">تنظیمات زبان</h3>
                </div>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">زبان پیش‌فرض</label>
                    <select className="mt-1 block w-full border rounded p-2">
                      <option>فارسی</option>
                      <option>English</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">زبان‌های فعال</label>
                    <div className="mt-2 space-y-2">
                      <label className="flex items-center">
                        <input type="checkbox" className="rounded" />
                        <span className="mr-2">فارسی</span>
                      </label>
                      <label className="flex items-center">
                        <input type="checkbox" className="rounded" />
                        <span className="mr-2">English</span>
                      </label>
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Notification Settings */}
            <Card>
              <CardContent className="p-4">
                <div className="flex items-center space-x-2 mb-4">
                  <Bell className="w-5 h-5 text-gray-500" />
                  <h3 className="font-medium">تنظیمات اعلان‌ها</h3>
                </div>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">اعلان‌های ایمیلی</label>
                    <div className="mt-2 space-y-2">
                      <label className="flex items-center">
                        <input type="checkbox" className="rounded" />
                        <span className="mr-2">محتوای جدید</span>
                      </label>
                      <label className="flex items-center">
                        <input type="checkbox" className="rounded" />
                        <span className="mr-2">نظرات جدید</span>
                      </label>
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Security Settings */}
            <Card>
              <CardContent className="p-4">
                <div className="flex items-center space-x-2 mb-4">
                  <Shield className="w-5 h-5 text-gray-500" />
                  <h3 className="font-medium">تنظیمات امنیتی</h3>
                </div>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">احراز هویت دو مرحله‌ای</label>
                    <div className="mt-2">
                      <label className="flex items-center">
                        <input type="checkbox" className="rounded" />
                        <span className="mr-2">فعال‌سازی برای همه کاربران</span>
                      </label>
                    </div>
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">محدودیت IP</label>
                    <textarea 
                      placeholder="هر IP در یک خط"
                      className="mt-1 block w-full border rounded p-2" 
                      rows={3}
                    ></textarea>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Database Settings */}
            <Card>
              <CardContent className="p-4">
                <div className="flex items-center space-x-2 mb-4">
                  <Database className="w-5 h-5 text-gray-500" />
                  <h3 className="font-medium">تنظیمات پایگاه داده</h3>
                </div>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">بک‌آپ خودکار</label>
                    <select className="mt-1 block w-full border rounded p-2">
                      <option>هر روز</option>
                      <option>هر هفته</option>
                      <option>هر ماه</option>
                    </select>
                  </div>
                  <button className="w-full px-4 py-2 bg-gray-100 rounded">
                    ایجاد بک‌آپ دستی
                  </button>
                </div>
              </CardContent>
            </Card>