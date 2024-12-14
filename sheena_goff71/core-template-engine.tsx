import React, { useState, useMemo } from 'react';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';

// Template Registry
const templateConfigs = {
  standard: {
    layout: 'single',
    zones: ['header', 'main', 'footer'],
    gridClass: 'grid grid-cols-1 gap-8'
  },
  twoCols: {
    layout: 'split',
    zones: ['header', 'main', 'sidebar', 'footer'],
    gridClass: 'grid grid-cols-1 md:grid-cols-12 gap-8'
  },
  fullWidth: {
    layout: 'full',
    zones: ['header', 'main', 'footer'],
    gridClass: 'grid grid-cols-1 gap-8 w-full'
  }
};

// Core Template Engine
export default function TemplateEngine({ 
  template = 'standard',
  content = {},
  media = [],
  className = '' 
}) {
  const [activeZone, setActiveZone] = useState(null);
  const [mediaPage, setMediaPage] = useState(0);
  const [selectedMedia, setSelectedMedia] = useState(null);

  const config = useMemo(() => 
    templateConfigs[template] || templateConfigs.standard,
    [template]
  );

  const itemsPerPage = 12;
  const mediaPages = Math.ceil(media.length / itemsPerPage);
  const currentMedia = media.slice(
    mediaPage * itemsPerPage, 
    (mediaPage + 1) * itemsPerPage
  );

  return (
    <div className={`template-engine ${className}`}>
      <div className={config.gridClass}>
        {/* Content Zones */}
        {config.zones.map(zone => (
          <div 
            key={zone}
            className={`zone-${zone} ${
              zone === 'main' ? 'md:col-span-8' : 
              zone === 'sidebar' ? 'md:col-span-4' : 'col-span-full'
            }`}
          >
            {content[zone]}
          </div>
        ))}

        {/* Media Gallery */}
        {media.length > 0 && (
          <div className="col-span-full">
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
              {currentMedia.map((item, idx) => (
                <div 
                  key={item.id || idx} 
                  className="relative aspect-square"
                >
                  <img
                    src={item.url || '/api/placeholder/400/320'}
                    alt={item.alt || ''}
                    className="w-full h-full object-cover rounded-lg cursor-pointer"
                    onClick={() => setSelectedMedia(item)}
                  />
                </div>
              ))}
            </div>

            {/* Pagination */}
            {mediaPages > 1 && (
              <div className="flex justify-center items-center gap-4 mt-6">
                <button 
                  onClick={() => setMediaPage(p => Math.max(0, p - 1))}
                  disabled={mediaPage === 0}
                  className="p-2 disabled:opacity-50"
                >
                  <ChevronLeft className="w-5 h-5" />
                </button>
                
                <span>
                  {mediaPage + 1} / {mediaPages}
                </span>
                
                <button
                  onClick={() => setMediaPage(p => Math.min(mediaPages - 1, p + 1))}
                  disabled={mediaPage === mediaPages - 1}
                  className="p-2 disabled:opacity-50"
                >
                  <ChevronRight className="w-5 h-5" />
                </button>
              </div>
            )}
          </div>
        )}
      </div>

      {/* Media Lightbox */}
      {selectedMedia && (
        <div className="fixed inset-0 z-50 bg-black/90 flex items-center justify-center">
          <button
            onClick={() => setSelectedMedia(null)}
            className="absolute top-4 right-4 text-white hover:text-gray-300"
          >
            <X className="w-6 h-6" />
          </button>
          
          <img
            src={selectedMedia.url}
            alt={selectedMedia.alt || ''}
            className="max-h-[90vh] max-w-[90vw] object-contain"
          />
        </div>
      )}
    </div>
  );
}
