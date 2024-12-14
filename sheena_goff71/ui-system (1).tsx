import React, { useState, useCallback, useMemo } from 'react';
import { ChevronLeft, ChevronRight, X, AlertCircle } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';

// Core Display System
export default function DisplaySystem({ 
  content = {}, 
  media = [], 
  template = 'default',
  className = ''
}) {
  const [activeZone, setActiveZone] = useState(null);
  const [activeMedia, setActiveMedia] = useState(null);

  const layoutConfig = useMemo(() => ({
    default: {
      grid: 'grid-cols-1',
      zones: ['header', 'main', 'footer']
    },
    withSidebar: {
      grid: 'grid-cols-1 md:grid-cols-12',
      zones: ['header', 'main:8', 'sidebar:4', 'footer']
    },
    fullWidth: {
      grid: 'grid-cols-1',
      zones: ['header', 'hero', 'main', 'footer']
    },
    magazine: {
      grid: 'grid-cols-1 md:grid-cols-12',
      zones: ['header', 'featured:8', 'sidebar:4', 'content', 'footer']
    }
  }), []);

  const currentLayout = layoutConfig[template] || layoutConfig.default;

  return (
    <div className={`display-system ${className}`}>
      {/* Main Content */}
      <ContentLayout 
        layout={currentLayout}
        content={content}
        onZoneClick={setActiveZone}
      />

      {/* Media Gallery */}
      {media && media.length > 0 && (
        <MediaGallery
          items={media}
          onSelect={setActiveMedia}
        />
      )}

      {/* Zone Editor */}
      {activeZone && (
        <ZoneEditor
          zone={activeZone}
          content={content[activeZone]}
          onClose={() => setActiveZone(null)}
        />
      )}

      {/* Media Viewer */}
      {activeMedia && (
        <MediaViewer
          item={activeMedia}
          onClose={() => setActiveMedia(null)}
        />
      )}
    </div>
  );
}

// Content Layout Component
const ContentLayout = ({ layout, content, onZoneClick }) => {
  const renderZone = (zone) => {
    const [name, span] = zone.split(':');
    return (
      <div 
        key={name}
        className={`zone ${span ? `md:col-span-${span}` : 'col-span-full'}`}
      >
        <ContentZone
          name={name}
          content={content[name]}
          onClick={() => onZoneClick?.(name)}
        />
      </div>
    );
  };

  return (
    <div className={`grid gap-6 ${layout.grid}`}>
      {layout.zones.map(renderZone)}
    </div>
  );
};

// Content Zone Component
const ContentZone = ({ name, content, onClick }) => {
  const zoneStyles = {
    header: 'bg-white border-b',
    hero: 'bg-gray-50 py-12',
    main: 'py-8',
    sidebar: 'bg-gray-50 p-6 rounded-lg',
    featured: 'bg-white p-6 shadow-sm rounded-lg',
    footer: 'bg-gray-100 border-t'
  };

  return (
    <div 
      className={`content-zone ${zoneStyles[name] || ''}`}
      onClick={onClick}
    >
      {content ? (
        <div className="prose max-w-none">
          {typeof content === 'string' ? (
            <div dangerouslySetInnerHTML={{ __html: content }} />
          ) : content}
        </div>
      ) : (
        <Alert>
          <AlertCircle className="h-4 w-4" />
          <AlertDescription>
            No content available for {name} zone
          </AlertDescription>
        </Alert>
      )}
    </div>
  );
};

// Media Gallery Component
const MediaGallery = ({ items = [], onSelect }) => {
  const [page, setPage] = useState(0);
  const itemsPerPage = 12;
  const totalPages = Math.ceil(items.length / itemsPerPage);
  const currentItems = items.slice(
    page * itemsPerPage,
    (page + 1) * itemsPerPage
  );

  return (
    <div className="media-gallery mt-8 space-y-6">
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        {currentItems.map((item, idx) => (
          <MediaItem
            key={item?.id || idx}
            item={item}
            onClick={() => onSelect?.(item)}
          />
        ))}
      </div>

      {totalPages > 1 && (
        <Pagination
          currentPage={page}
          totalPages={totalPages}
          onPageChange={setPage}
        />
      )}
    </div>
  );
};

// Media Item Component
const MediaItem = ({ item, onClick }) => (
  <div 
    className="relative aspect-square group cursor-pointer overflow-hidden rounded-lg bg-gray-100"
    onClick={onClick}
  >
    <img
      src={item?.url || '/api/placeholder/400/320'}
      alt={item?.alt || 'Gallery image'}
      className="w-full h-full object-cover transition-transform group-hover:scale-105"
    />
    <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity" />
  </div>
);

// Media Viewer Component
const MediaViewer = ({ item, onClose }) => (
  <div className="fixed inset-0 z-50 bg-black/90 flex items-center justify-center">
    <button
      onClick={onClose}
      className="absolute top-4 right-4 text-white hover:text-gray-300"
    >
      <X className="w-6 h-6" />
    </button>
    <img
      src={item?.url || '/api/placeholder/400/320'}
      alt={item?.alt || 'Full size image'}
      className="max-h-[90vh] max-w-[90vw] object-contain"
    />
  </div>
);

// Zone Editor Component (Placeholder for editing functionality)
const ZoneEditor = ({ zone, content, onClose }) => (
  <div className="fixed inset-0 z-40 bg-black/50 flex items-center justify-center">
    <div className="bg-white rounded-lg p-6 max-w-2xl w-full mx-4">
      <div className="flex justify-between items-center mb-4">
        <h3 className="text-lg font-semibold">Edit {zone} Zone</h3>
        <button onClick={onClose}>
          <X className="w-5 h-5" />
        </button>
      </div>
      <div className="prose max-w-none">
        {content || `No content in ${zone} zone`}
      </div>
    </div>
  </div>
);

// Pagination Component
const Pagination = ({ currentPage, totalPages, onPageChange }) => (
  <div className="flex items-center justify-center gap-4">
    <button
      onClick={() => onPageChange(Math.max(0, currentPage - 1))}
      disabled={currentPage === 0}
      className="p-2 rounded-full hover:bg-gray-100 disabled:opacity-50"
    >
      <ChevronLeft className="w-5 h-5" />
    </button>
    
    <span className="text-sm">
      Page {currentPage + 1} of {totalPages}
    </span>
    
    <button
      onClick={() => onPageChange(Math.min(totalPages - 1, currentPage + 1))}
      disabled={currentPage === totalPages - 1}
      className="p-2 rounded-full hover:bg-gray-100 disabled:opacity-50"
    >
      <ChevronRight className="w-5 h-5" />
    </button>
  </div>
);
