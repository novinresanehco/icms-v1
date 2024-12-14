import React from 'react';
import { Alert } from '@/components/ui/alert';

const TemplateEngine = {
  ContentDisplay: ({ content }) => (
    <div className="w-full max-w-6xl mx-auto bg-white shadow-sm">
      <div className="p-6">
        <h1 className="text-2xl font-bold">{content.title}</h1>
        <div className="prose" dangerouslySetInnerHTML={{ __html: content.body }} />
      </div>
    </div>
  ),

  MediaGallery: ({ items }) => (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
      {items?.map((item, i) => (
        <div key={i} className="bg-gray-100 rounded-lg overflow-hidden">
          <img src={item.url} alt={item.title} className="w-full h-48 object-cover" />
          <div className="p-2 bg-black/50">
            <p className="text-white text-sm">{item.title}</p>
          </div>
        </div>
      ))}
    </div>
  ),

  Components: {
    Card: ({ title, children }) => (
      <div className="bg-white rounded-lg shadow p-4">
        {title && <h3 className="text-lg font-bold mb-2">{title}</h3>}
        {children}
      </div>
    ),

    Alert: ({ message }) => (
      <Alert>{message}</Alert>
    )
  }
};

export default TemplateEngine;
