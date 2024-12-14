import React, { useState } from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { UserPlus, Shield, Key, Users, Search, Filter } from 'lucide-react';

const UserManagement = () => {
  const [activeTab, setActiveTab] = useState('users');

  const users = [
    { id: 1, name: 'محمد احمدی', email: 'mohammad@example.com', role: 'مدیر', status: 'فعال' },
    { id: 2, name: 'سارا محمدی', email: 'sara@example.com', role: 'ویرایشگر', status: 'فعال' },
    { id: 3, name: 'علی رضایی', email: 'ali@example.com', role: 'نویسنده', status: 'غیرفعال' },
    { id: 4, name: 'مریم کریمی', email: 'maryam@example.com', role: 'مدیر محتوا', status: 'فعال' }
  ];

  const roles = [
    { id: 1, name: 'مدیر', users: 2, permissions: 15 },
    { id: 2, name: 'ویرایشگر', users: 5, permissions: 10 },
    { id: 3, name: 'نویسنده', users: 8, permissions: 7 },
    { id: 4, name: 'مدیر محتوا', users: 3, permissions: 12 }
  ];

  return (
    <div className="w-full space-y-4">
      <Card>
        <CardHeader className="border-b">
          <div className="flex justify-between items-center">
            <CardTitle>مدیریت کاربران و دسترسی‌ها</CardTitle>
            <button className="px-4 py-2 bg-blue-500 text-white rounded flex items-center">
              <UserPlus className="w-4 h-4 mr-2" />
              افزودن کاربر جدید
            </button>
          </div>
        </CardHeader>

        <CardContent>
          <div className="flex space-x-4 mb-6 border-b">
            <button 
              className={`px-4 py-2 ${activeTab === 'users' ? 'border-b-2 border-blue-500 text-blue-500' : 'text-gray-500'}`}
              onClick={() => setActiveTab('users')}
            >
              <span className="flex items-center">
                <Users className="w-4 h-4 mr-2" />
                کاربران
              </span>
            </button>
            <button 
              className={`px-4 py-2 ${activeTab === 'roles' ? 'border-b-2 border-blue-500 text-blue-500' : 'text-gray-500'}`}
              onClick={() => setActiveTab('roles')}
            >
              <span className="flex items-center">
                <Shield className="w-4 h-4 mr-2" />
                نقش‌ها
              </span>
            </button>
            <button 
              className={`px-4 py-2 ${activeTab === 'permissions' ? 'border-b-2 border-blue-500 text-blue-500' : 'text-gray-500'}`}
              onClick={() => setActiveTab('permissions')}
            >
              <span className="flex items-center">
                <Key className="w-4 h-4 mr-2" />
                دسترسی‌ها
              </span>
            </button>
          </div>

          {/* Search and Filter */}
          <div className="flex gap-4 mb-6">
            <div className="flex-1 relative">
              <Search className="w-5 h-5 absolute left-3 top-2.5 text-gray-400" />
              <input
                type="text"
                placeholder="جستجو..."
                className="w-full pl-10 pr-4 py-2 border rounded"
              />
            </div>
            <button className="px-4 py-2 border rounded flex items-center">
              <Filter className="w-4 h-4 mr-2" />
              فیلتر
            </button>
          </div>

          {activeTab === 'users' && (
            <div className="border rounded">
              <table className="min-w-full divide-y">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">نام</th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">ایمیل</th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">نقش</th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">وضعیت</th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">عملیات</th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y">
                  {users.map(user => (
                    <tr key={user.id}>
                      <td className="px-6 py-4 whitespace-nowrap">{user.name}</td>
                      <td className="px-6 py-4 whitespace-nowrap">{user.email}</td>
                      <td className="px-6 py-4 whitespace-nowrap">{user.role}</td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`px-2 py-1 text-xs rounded-full ${
                          user.status === 'فعال' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                        }`}>
                          {user.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <button className="text-blue-500 hover:text-blue-700">ویرایش</button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {activeTab === 'roles' && (
            <div className="grid grid-cols-2 gap-4">
              {roles.map(role => (
                <Card key={role.id}>
                  <CardContent className="p-4">
                    <div className="flex justify-between items-start">
                      <div>
                        <h3 className="font-medium text-lg">{role.name}</h3>
                        <p className="text-sm text-gray-500 mt-1">
                          {role.users} کاربر • {role.permissions} دسترسی
                        </p>
                      </div>
                      <button className="text-blue-500 hover:text-blue-700">ویرایش</button>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default UserManagement;
