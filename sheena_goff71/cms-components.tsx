import React from 'react';
import { Alert } from '@/components/ui/alert';

const ContentDisplay = ({ content }) => (
  <div className="max-w-6xl mx-auto bg-white rounded-lg shadow">
    <div className="p-6">
      <h1 className="text-2xl font-bold mb-4">{content.title}</h1>
      <div className="prose" dangerouslySetInnerHTML={{ __html: content.body }} />
    </div>
  </div>
);

const MediaGallery = ({ items = [] }) => (
  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
    {items.map((item, i) => (
      <div key={i} className="relative bg-gray-100 rounded-lg overflow-hidden">
        <img src={item.url} alt={item.title} className="w-full h-48 object-cover" loading="lazy" />
        <div className="absolute bottom-0 w-full p-2 bg-black/50">
          <p className="text-white text-sm">{item.title}</p>
        </div>
      </div>
    ))}
  </div>
);

const TemplateRenderer = ({ template, data }) => (
  <div className="template-wrapper">
    <div dangerouslySetInnerHTML={{ __html: template }} />
  </div>
);

const Components = {
  ContentDisplay,
  MediaGallery,
  TemplateRenderer,
  Alert
};

export default Components;
