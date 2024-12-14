import React, { useState } from 'react';
import { AlertDialog, Alert } from '@/components/ui/alert';
import { Camera } from 'lucide-react';

export default function AdminInterface() {
  const [currentUser, setCurrentUser] = useState(null);
  const [alertMessage, setAlertMessage] = useState('');

  const handleLogin = async (credentials) => {
    try {
      const response = await fetch('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(credentials)
      });
      const data = await response.json();
      if (data.status === 'success') {
        setCurrentUser(data.user);
      }
    } catch (error) {
      setAlertMessage('Authentication failed');
    }
  };

  return (
    <div className="min-h-screen bg-gray-100">
      <nav className="bg-white shadow-sm">
        <div className="mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex justify-between h-16">
            <div className="flex">
              <div className="flex-shrink-0 flex items-center">
                <Camera className="h-8 w-8 text-blue-500" />
              </div>
              <div className="hidden sm:ml-6 sm:flex sm:space-x-8">
                <a href="#" className="text-gray-900 inline-flex items-center px-1 pt-1 text-sm font-medium">
                  Dashboard
                </a>
                <a href="#" className="text-gray-500 hover:text-gray-900 inline-flex items-center px-1 pt-1 text-sm font-medium">
                  Content
                </a>
                <a href="#" className="text-gray-500 hover:text-gray-900 inline-flex items-center px-1 pt-1 text-sm font-medium">
                  Media
                </a>
                <a href="#" className="text-gray-500 hover:text-gray-900 inline-flex items-center px-1 pt-1 text-sm font-medium">
                  Settings
                </a>
              </div>
            </div>
          </div>
        </div>
      </nav>

      <div className="py-10">
        <main>
          <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
            {!currentUser ? (
              <div className="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <div className="p-6">
                  <LoginForm onSubmit={handleLogin} />
                </div>
              </div>
            ) : (
              <div className="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <div className="p-6">
                  <DashboardContent user={currentUser} />
                </div>
              </div>
            )}
          </div>
        </main>
      </div>

      {alertMessage && (
        <Alert variant="destructive">
          <AlertDialog>{alertMessage}</AlertDialog>
        </Alert>
      )}
    </div>
  );
}

function LoginForm({ onSubmit }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  const handleSubmit = (e) => {
    e.preventDefault();
    onSubmit({ email, password });
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      <div>
        <label htmlFor="email" className="block text-sm font-medium text-gray-700">
          Email
        </label>
        <input
          type="email"
          id="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
          className="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"
        />
      </div>
      <div>
        <label htmlFor="password" className="block text-sm font-medium text-gray-700">
          Password
        </label>
        <input
          type="password"
          id="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
          className="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"
        />
      </div>
      <button
        type="submit"
        className="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
      >
        Sign in
      </button>
    </form>
  );
}

function DashboardContent({ user }) {
  return (
    <div className="space-y-6">
      <div className="bg-white px-4 py-5 border-b border-gray-200 sm:px-6">
        <h3 className="text-lg leading-6 font-medium text-gray-900">
          Dashboard
        </h3>
      </div>
      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div className="bg-white overflow-hidden shadow rounded-lg">
          <div className="px-4 py-5 sm:p-6">
            <dt className="text-sm font-medium text-gray-500 truncate">
              Total Content
            </dt>
            <dd className="mt-1 text-3xl font-semibold text-gray-900">
              0
            </dd>
          </div>
        </div>
        <div className="bg-white overflow-hidden shadow rounded-lg">
          <div className="px-4 py-5 sm:p-6">
            <dt className="text-sm font-medium text-gray-500 truncate">
              Published
            </dt>
            <dd className="mt-1 text-3xl font-semibold text-gray-900">
              0
            </dd>
          </div>
        </div>
        <div className="bg-white overflow-hidden shadow rounded-lg">
          <div className="px-4 py-5 sm:p-6">
            <dt className="text-sm font-medium text-gray-500 truncate">
              Draft
            </dt>
            <dd className="mt-1 text-3xl font-semibold text-gray-900">
              0
            </dd>
          </div>
        </div>
      </div>
    </div>
  );
}
