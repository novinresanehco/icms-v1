import React, { useState, useEffect } from 'react';
import { Alert } from '@/components/ui/alert';

const ContentDisplay = ({ content, securityContext }) => {
  return (
    <div className="w-full max-w-6xl mx-auto bg-white shadow-sm">
      <div className="p-6">
        <h1 className="text-2xl font-bold mb-4">{content.title}</h1>
        <div className="prose max-w-none" 
             dangerouslySetInnerHTML={{ __html: content.body }} />
      </div>
    </div>
  );
};

const MediaGallery = ({ items = [] }) => {
  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
      {items.map((item, index) => (
        <div key={index} className="relative bg-gray-100 rounded-lg overflow-hidden">
          <img 
            src={item.url} 
            alt={item.title}
            className="w-full h-48 object-cover"
            loading="lazy"
          />
          <div className="absolute bottom-0 w-full p-2 bg-black/50">
            <p className="text-white text-sm truncate">{item.title}</p>
          </div>
        </div>
      ))}
    </div>
  );
};

const UIComponents = {
  ContentDisplay,
  MediaGallery,
  Alert,
  Button: ({ children, ...props }) => (
    <button className="px-4 py-2 bg-blue-600 text-white rounded-lg" {...props}>
      {children}
    </button>
  ),
  Card: ({ children, title }) => (
    <div className="bg-white rounded-lg shadow-sm p-4">
      {title && <h3 className="text-lg font-semibold mb-2">{title}</h3>}
      {children}
    </div>
  )
};

export default UIComponents;
